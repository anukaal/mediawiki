<?php

/**
 * Convert a JSON abstract schema to a schema file in the given DBMS type
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

use Wikimedia\Rdbms\DoctrineSchemaBuilderFactory;

require_once __DIR__ . '/Maintenance.php';

/**
 * Maintenance script to generate schema from abstract json files.
 *
 * @ingroup Maintenance
 */
class GenerateSchemaSql extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Build SQL files from abstract JSON files' );

		$this->addOption(
			'json',
			'Path to the json file. Default: tables.json',
			false,
			true
		);
		$this->addOption(
			'sql',
			'Path to output. Default: tables-generated.sql',
			false,
			true
		);
		$this->addOption(
			'type',
			'Can be either \'mysql\', \'sqlite\', or \'postgres\'. Default: mysql',
			false,
			true
		);
	}

	public function execute() {
		$jsonFile = $this->getOption( 'json', 'tables.json' );
		$sqlFile = $this->getOption( 'sql', 'tables-generated.sql' );
		$abstractSchema = json_decode( file_get_contents( $jsonFile ), true );
		$schemaBuilder = ( new DoctrineSchemaBuilderFactory() )->getSchemaBuilder(
			$this->getOption( 'type', 'mysql' )
		);
		foreach ( $abstractSchema as $table ) {
			$schemaBuilder->addTable( $table );
		}
		file_put_contents( $sqlFile, $schemaBuilder->getSql() );
	}

}

$maintClass = GenerateSchemaSql::class;
require_once RUN_MAINTENANCE_IF_MAIN;
