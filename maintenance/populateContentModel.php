<?php
/**
 * Populate the page_content_model and {rev,ar}_content_{model,format} fields.
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

require_once __DIR__ . '/Maintenance.php';

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

/**
 * Usage:
 *  populateContentModel.php --ns=1 --table=page
 */
class PopulateContentModel extends Maintenance {
	protected $wikiId;
	/** @var WANObjectCache */
	protected $wanCache;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Populate the various content_* fields' );
		$this->addOption( 'ns', 'Namespace to run in, or "all" for all namespaces', true, true );
		$this->addOption( 'table', 'Table to run in', true, true );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		$dbw = $this->getDB( DB_MASTER );

		$this->wikiId = $dbw->getDomainID();
		$this->wanCache = MediaWikiServices::getInstance()->getMainWANObjectCache();

		$ns = $this->getOption( 'ns' );
		if ( !ctype_digit( $ns ) && $ns !== 'all' ) {
			$this->fatalError( 'Invalid namespace' );
		}
		$ns = $ns === 'all' ? 'all' : (int)$ns;
		$table = $this->getOption( 'table' );
		switch ( $table ) {
			case 'revision':
			case 'archive':
				$this->populateRevisionOrArchive( $dbw, $table, $ns );
				break;
			case 'page':
				$this->populatePage( $dbw, $ns );
				break;
			default:
				$this->fatalError( "Invalid table name: $table" );
		}
	}

	protected function clearCache( $page_id, $rev_id ) {
		$contentModelKey = $this->wanCache->makeKey( 'page-content-model', $rev_id );
		$revisionKey =
			$this->wanCache->makeGlobalKey( 'revision', $this->wikiId, $page_id, $rev_id );

		// WikiPage content model cache
		$this->wanCache->delete( $contentModelKey );

		// Revision object cache, which contains a content model
		$this->wanCache->delete( $revisionKey );
	}

	private function updatePageRows( IDatabase $dbw, $pageIds, $model ) {
		$count = count( $pageIds );
		$this->output( "Setting $count rows to $model..." );
		$dbw->update(
			'page',
			[ 'page_content_model' => $model ],
			[ 'page_id' => $pageIds ],
			__METHOD__
		);
		MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->waitForReplication();
		$this->output( "done.\n" );
	}

	protected function populatePage( IDatabase $dbw, $ns ) {
		$toSave = [];
		$lastId = 0;
		$nsCondition = $ns === 'all' ? [] : [ 'page_namespace' => $ns ];
		$batchSize = $this->getBatchSize();
		do {
			$rows = $dbw->select(
				'page',
				[ 'page_namespace', 'page_title', 'page_id' ],
				[
					'page_content_model' => null,
					'page_id > ' . $dbw->addQuotes( $lastId ),
				] + $nsCondition,
				__METHOD__,
				[ 'LIMIT' => $batchSize, 'ORDER BY' => 'page_id ASC' ]
			);
			$this->output( "Fetched {$rows->numRows()} rows.\n" );
			foreach ( $rows as $row ) {
				$title = Title::newFromRow( $row );
				$model = ContentHandler::getDefaultModelFor( $title );
				$toSave[$model][] = $row->page_id;
				if ( count( $toSave[$model] ) >= $batchSize ) {
					$this->updatePageRows( $dbw, $toSave[$model], $model );
					unset( $toSave[$model] );
				}
				$lastId = $row->page_id;
			}
		} while ( $rows->numRows() >= $batchSize );
		foreach ( $toSave as $model => $pages ) {
			$this->updatePageRows( $dbw, $pages, $model );
		}
	}

	private function updateRevisionOrArchiveRows( IDatabase $dbw, $ids, $model, $table ) {
		$prefix = $table === 'archive' ? 'ar' : 'rev';
		$model_column = "{$prefix}_content_model";
		$format_column = "{$prefix}_content_format";
		$key = "{$prefix}_id";

		$count = count( $ids );
		$format = MediaWikiServices::getInstance()
			->getContentHandlerFactory()
			->getContentHandler( $model )
			->getDefaultFormat();
		$this->output( "Setting $count rows to $model / $format..." );
		$dbw->update(
			$table,
			[ $model_column => $model, $format_column => $format ],
			[ $key => $ids ],
			__METHOD__
		);

		$this->output( "done.\n" );
	}

	protected function populateRevisionOrArchive( IDatabase $dbw, $table, $ns ) {
		$prefix = $table === 'archive' ? 'ar' : 'rev';
		$model_column = "{$prefix}_content_model";
		$format_column = "{$prefix}_content_format";
		$key = "{$prefix}_id";
		if ( $table === 'archive' ) {
			$selectTables = 'archive';
			$fields = [ 'ar_namespace', 'ar_title' ];
			$join_conds = [];
			$where = $ns === 'all' ? [] : [ 'ar_namespace' => $ns ];
			$page_id_column = 'ar_page_id';
			$rev_id_column = 'ar_rev_id';
		} else { // revision
			$selectTables = [ 'revision', 'page' ];
			$fields = [ 'page_title', 'page_namespace' ];
			$join_conds = [ 'page' => [ 'JOIN', 'rev_page=page_id' ] ];
			$where = $ns === 'all' ? [] : [ 'page_namespace' => $ns ];
			$page_id_column = 'rev_page';
			$rev_id_column = 'rev_id';
		}

		$toSave = [];
		$idsToClear = [];
		$lastId = 0;
		$batchSize = $this->getBatchSize();
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		do {
			$rows = $dbw->select(
				$selectTables,
				array_merge(
					$fields,
					[ $model_column, $format_column, $key, $page_id_column, $rev_id_column ]
				),
				// @todo support populating format if model is already set
				[
					$model_column => null,
					"$key > " . $dbw->addQuotes( $lastId ),
				] + $where,
				__METHOD__,
				[ 'LIMIT' => $batchSize, 'ORDER BY' => "$key ASC" ],
				$join_conds
			);
			$this->output( "Fetched {$rows->numRows()} rows.\n" );
			foreach ( $rows as $row ) {
				if ( $table === 'archive' ) {
					$title = Title::makeTitle( $row->ar_namespace, $row->ar_title );
				} else {
					$title = Title::newFromRow( $row );
				}
				$lastId = $row->{$key};
				try {
					$handler = MediaWikiServices::getInstance()
						->getContentHandlerFactory()
						->getContentHandler( $title->getContentModel() );
				} catch ( MWException $e ) {
					$this->error( "Invalid content model for $title" );
					continue;
				}
				$defaultModel = $handler->getModelID();
				$defaultFormat = $handler->getDefaultFormat();
				$dbModel = $row->{$model_column};
				$dbFormat = $row->{$format_column};
				$id = $row->{$key};
				if ( $dbModel === null && $dbFormat === null ) {
					// Set the defaults
					$toSave[$defaultModel][] = $row->{$key};
					$idsToClear[] = [
						'page_id' => $row->{$page_id_column},
						'rev_id' => $row->{$rev_id_column},
					];
				} else { // $dbModel === null, $dbFormat set.
					if ( $dbFormat === $defaultFormat ) {
						$toSave[$defaultModel][] = $row->{$key};
						$idsToClear[] = [
							'page_id' => $row->{$page_id_column},
							'rev_id' => $row->{$rev_id_column},
						];
					} else { // non-default format, just update now
						$this->output( "Updating model to match format for $table $id of $title... " );
						$dbw->update(
							$table,
							[ $model_column => $defaultModel ],
							[ $key => $id ],
							__METHOD__
						);
						$lbFactory->waitForReplication();
						$this->clearCache( $row->{$page_id_column}, $row->{$rev_id_column} );
						$this->output( "done.\n" );
						continue;
					}
				}

				if ( count( $toSave[$defaultModel] ) >= $batchSize ) {
					$this->updateRevisionOrArchiveRows( $dbw, $toSave[$defaultModel], $defaultModel, $table );
					unset( $toSave[$defaultModel] );
				}
			}
		} while ( $rows->numRows() >= $batchSize );
		foreach ( $toSave as $model => $ids ) {
			$this->updateRevisionOrArchiveRows( $dbw, $ids, $model, $table );
		}

		foreach ( $idsToClear as $idPair ) {
			$this->clearCache( $idPair['page_id'], $idPair['rev_id'] );
		}
	}
}

$maintClass = PopulateContentModel::class;
require_once RUN_MAINTENANCE_IF_MAIN;
