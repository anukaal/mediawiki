<?php
/**
 * Adds blobs from a given external storage cluster to the blob_tracking table.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\Rdbms\DBConnectionError;

require __DIR__ . '/../commandLine.inc';

if ( count( $args ) < 1 ) {
	echo "Usage: php trackBlobs.php <cluster> [... <cluster>]\n";
	echo "Adds blobs from a given ES cluster to the blob_tracking table\n";
	echo "Automatically deletes the tracking table and starts from the start again when restarted.\n";

	exit( 1 );
}
$tracker = new TrackBlobs( $args );
$tracker->run();
echo "All done.\n";

class TrackBlobs {
	public $clusters, $textClause;
	public $doBlobOrphans;
	public $trackedBlobs = [];

	public $batchSize = 1000;
	public $reportingInterval = 10;

	public function __construct( $clusters ) {
		$this->clusters = $clusters;
		if ( extension_loaded( 'gmp' ) ) {
			$this->doBlobOrphans = true;
			foreach ( $clusters as $cluster ) {
				$this->trackedBlobs[$cluster] = gmp_init( 0 );
			}
		} else {
			echo "Warning: the gmp extension is needed to find orphan blobs\n";
		}
	}

	public function run() {
		$this->checkIntegrity();
		$this->initTrackingTable();
		$this->trackRevisions();
		$this->trackOrphanText();
		if ( $this->doBlobOrphans ) {
			$this->findOrphanBlobs();
		}
	}

	private function checkIntegrity() {
		echo "Doing integrity check...\n";
		$dbr = wfGetDB( DB_REPLICA );

		// Scan for HistoryBlobStub objects in the text table (T22757)

		$exists = $dbr->selectField( 'text', '1',
			'old_flags LIKE \'%object%\' AND old_flags NOT LIKE \'%external%\' ' .
			'AND LOWER(CONVERT(LEFT(old_text,22) USING latin1)) = \'o:15:"historyblobstub"\'',
			__METHOD__
		);

		if ( $exists ) {
			echo "Integrity check failed: found HistoryBlobStub objects in your text table.\n" .
				"This script could destroy these objects if it continued. Run resolveStubs.php\n" .
				"to fix this.\n";
			exit( 1 );
		}

		echo "Integrity check OK\n";
	}

	private function initTrackingTable() {
		$dbw = wfGetDB( DB_MASTER );
		if ( $dbw->tableExists( 'blob_tracking' ) ) {
			$dbw->query( 'DROP TABLE ' . $dbw->tableName( 'blob_tracking' ) );
			$dbw->query( 'DROP TABLE ' . $dbw->tableName( 'blob_orphans' ) );
		}
		$dbw->sourceFile( __DIR__ . '/blob_tracking.sql' );
	}

	private function getTextClause() {
		if ( !$this->textClause ) {
			$dbr = wfGetDB( DB_REPLICA );
			$this->textClause = '';
			foreach ( $this->clusters as $cluster ) {
				if ( $this->textClause != '' ) {
					$this->textClause .= ' OR ';
				}
				$this->textClause .= 'old_text' . $dbr->buildLike( "DB://$cluster/", $dbr->anyString() );
			}
		}

		return $this->textClause;
	}

	private function interpretPointer( $text ) {
		if ( !preg_match( '!^DB://(\w+)/(\d+)(?:/([0-9a-fA-F]+)|)$!', $text, $m ) ) {
			return false;
		}

		return [
			'cluster' => $m[1],
			'id' => intval( $m[2] ),
			'hash' => $m[3] ?? null
		];
	}

	/**
	 *  Scan the revision table for rows stored in the specified clusters
	 */
	private function trackRevisions() {
		$dbw = wfGetDB( DB_MASTER );
		$dbr = wfGetDB( DB_REPLICA );

		$textClause = $this->getTextClause();
		$startId = 0;
		$endId = $dbr->selectField( 'revision', 'MAX(rev_id)', '', __METHOD__ );
		$batchesDone = 0;
		$rowsInserted = 0;

		echo "Finding revisions...\n";

		$fields = [ 'rev_id', 'rev_page', 'old_id', 'old_flags', 'old_text' ];
		$options = [
			'ORDER BY' => 'rev_id',
			'LIMIT' => $this->batchSize
		];
		$conds = [
			$textClause,
			'old_flags ' . $dbr->buildLike( $dbr->anyString(), 'external', $dbr->anyString() ),
		];
		$slotRoleStore = MediaWikiServices::getInstance()->getSlotRoleStore();
		$tables = [ 'revision', 'slots', 'content', 'text' ];
		$conds = array_merge( [
			'rev_id=slot_revision_id',
			'slot_role_id=' . $slotRoleStore->getId( SlotRecord::MAIN ),
			'content_id=slot_content_id',
			'SUBSTRING(content_address, 1, 3)=' . $dbr->addQuotes( 'tt:' ),
			'SUBSTRING(content_address, 4)=old_id',
		], $conds );
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		while ( true ) {
			$res = $dbr->select( $tables,
				$fields,
				array_merge( [
					'rev_id > ' . $dbr->addQuotes( $startId ),
				], $conds ),
				__METHOD__,
				$options
			);
			if ( !$res->numRows() ) {
				break;
			}

			$insertBatch = [];
			foreach ( $res as $row ) {
				$startId = $row->rev_id;
				$info = $this->interpretPointer( $row->old_text );
				if ( !$info ) {
					echo "Invalid DB:// URL in rev_id {$row->rev_id}\n";
					continue;
				}
				if ( !in_array( $info['cluster'], $this->clusters ) ) {
					echo "Invalid cluster returned in SQL query: {$info['cluster']}\n";
					continue;
				}
				$insertBatch[] = [
					'bt_page' => $row->rev_page,
					'bt_rev_id' => $row->rev_id,
					'bt_text_id' => $row->old_id,
					'bt_cluster' => $info['cluster'],
					'bt_blob_id' => $info['id'],
					'bt_cgz_hash' => $info['hash']
				];
				if ( $this->doBlobOrphans ) {
					gmp_setbit( $this->trackedBlobs[$info['cluster']], $info['id'] );
				}
			}
			$dbw->insert( 'blob_tracking', $insertBatch, __METHOD__ );
			$rowsInserted += count( $insertBatch );

			++$batchesDone;
			if ( $batchesDone >= $this->reportingInterval ) {
				$batchesDone = 0;
				echo "$startId / $endId\n";
				$lbFactory->waitForReplication();
			}
		}
		echo "Found $rowsInserted revisions\n";
	}

	/**
	 * Scan the text table for orphan text
	 * Orphan text here does not imply DB corruption -- deleted text tracked by the
	 * archive table counts as orphan for our purposes.
	 */
	private function trackOrphanText() {
		# Wait until the blob_tracking table is available in the replica DB
		$dbw = wfGetDB( DB_MASTER );
		$dbr = wfGetDB( DB_REPLICA );
		$pos = $dbw->getMasterPos();
		$dbr->masterPosWait( $pos, 100000 );

		$textClause = $this->getTextClause();
		$startId = 0;
		$endId = $dbr->selectField( 'text', 'MAX(old_id)', '', __METHOD__ );
		$rowsInserted = 0;
		$batchesDone = 0;
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		echo "Finding orphan text...\n";

		# Scan the text table for orphan text
		while ( true ) {
			$res = $dbr->select( [ 'text', 'blob_tracking' ],
				[ 'old_id', 'old_flags', 'old_text' ],
				[
					'old_id>' . $dbr->addQuotes( $startId ),
					$textClause,
					'old_flags ' . $dbr->buildLike( $dbr->anyString(), 'external', $dbr->anyString() ),
					'bt_text_id IS NULL'
				],
				__METHOD__,
				[
					'ORDER BY' => 'old_id',
					'LIMIT' => $this->batchSize
				],
				[ 'blob_tracking' => [ 'LEFT JOIN', 'bt_text_id=old_id' ] ]
			);
			$ids = [];
			foreach ( $res as $row ) {
				$ids[] = $row->old_id;
			}

			if ( !$res->numRows() ) {
				break;
			}

			$insertBatch = [];
			foreach ( $res as $row ) {
				$startId = $row->old_id;
				$info = $this->interpretPointer( $row->old_text );
				if ( !$info ) {
					echo "Invalid DB:// URL in old_id {$row->old_id}\n";
					continue;
				}
				if ( !in_array( $info['cluster'], $this->clusters ) ) {
					echo "Invalid cluster returned in SQL query\n";
					continue;
				}

				$insertBatch[] = [
					'bt_page' => 0,
					'bt_rev_id' => 0,
					'bt_text_id' => $row->old_id,
					'bt_cluster' => $info['cluster'],
					'bt_blob_id' => $info['id'],
					'bt_cgz_hash' => $info['hash']
				];
				if ( $this->doBlobOrphans ) {
					gmp_setbit( $this->trackedBlobs[$info['cluster']], $info['id'] );
				}
			}
			$dbw->insert( 'blob_tracking', $insertBatch, __METHOD__ );

			$rowsInserted += count( $insertBatch );
			++$batchesDone;
			if ( $batchesDone >= $this->reportingInterval ) {
				$batchesDone = 0;
				echo "$startId / $endId\n";
				$lbFactory->waitForReplication();
			}
		}
		echo "Found $rowsInserted orphan text rows\n";
	}

	/**
	 * Scan the blobs table for rows not registered in blob_tracking (and thus not
	 * registered in the text table).
	 *
	 * Orphan blobs are indicative of DB corruption. They are inaccessible and
	 * should probably be deleted.
	 */
	private function findOrphanBlobs() {
		if ( !extension_loaded( 'gmp' ) ) {
			echo "Can't find orphan blobs, need bitfield support provided by GMP.\n";

			return;
		}

		$dbw = wfGetDB( DB_MASTER );
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		foreach ( $this->clusters as $cluster ) {
			echo "Searching for orphan blobs in $cluster...\n";
			$lb = $lbFactory->getExternalLB( $cluster );
			try {
				$extDB = $lb->getMaintenanceConnectionRef( DB_REPLICA );
			} catch ( DBConnectionError $e ) {
				if ( strpos( $e->getMessage(), 'Unknown database' ) !== false ) {
					echo "No database on $cluster\n";
				} else {
					echo "Error on $cluster: " . $e->getMessage() . "\n";
				}
				continue;
			}
			$table = $extDB->getLBInfo( 'blobs table' );
			if ( $table === null ) {
				$table = 'blobs';
			}
			if ( !$extDB->tableExists( $table ) ) {
				echo "No blobs table on cluster $cluster\n";
				continue;
			}
			$startId = 0;
			$batchesDone = 0;
			$actualBlobs = gmp_init( 0 );
			$endId = $extDB->selectField( $table, 'MAX(blob_id)', '', __METHOD__ );

			// Build a bitmap of actual blob rows
			while ( true ) {
				$res = $extDB->select( $table,
					[ 'blob_id' ],
					[ 'blob_id > ' . $extDB->addQuotes( $startId ) ],
					__METHOD__,
					[ 'LIMIT' => $this->batchSize, 'ORDER BY' => 'blob_id' ]
				);

				if ( !$res->numRows() ) {
					break;
				}

				foreach ( $res as $row ) {
					gmp_setbit( $actualBlobs, $row->blob_id );
					$startId = $row->blob_id;
				}

				++$batchesDone;
				if ( $batchesDone >= $this->reportingInterval ) {
					$batchesDone = 0;
					echo "$startId / $endId\n";
				}
			}

			// Find actual blobs that weren't tracked by the previous passes
			// This is a set-theoretic difference A \ B, or in bitwise terms, A & ~B
			$orphans = gmp_and( $actualBlobs, gmp_com( $this->trackedBlobs[$cluster] ) );

			// Traverse the orphan list
			$insertBatch = [];
			$id = 0;
			$numOrphans = 0;
			while ( true ) {
				$id = gmp_scan1( $orphans, $id );
				if ( $id == -1 ) {
					break;
				}
				$insertBatch[] = [
					'bo_cluster' => $cluster,
					'bo_blob_id' => $id
				];
				if ( count( $insertBatch ) > $this->batchSize ) {
					$dbw->insert( 'blob_orphans', $insertBatch, __METHOD__ );
					$insertBatch = [];
				}

				++$id;
				++$numOrphans;
			}
			if ( $insertBatch ) {
				$dbw->insert( 'blob_orphans', $insertBatch, __METHOD__ );
			}
			echo "Found $numOrphans orphan(s) in $cluster\n";
		}
	}
}
