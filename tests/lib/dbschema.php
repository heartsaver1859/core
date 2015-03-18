<?php

/**
 * Copyright (c) 2012 Bart Visscher <bartv@thisnet.nl>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

use OCP\Security\ISecureRandom;

class Test_DBSchema extends \Test\TestCase {
	protected $schema_file = 'static://test_db_scheme';
	protected $schema_file2 = 'static://test_db_scheme2';
	protected $table1;
	protected $table2;

	protected function setUp() {
		parent::setUp();

		$dbfile = OC::$SERVERROOT.'/tests/data/db_structure.xml';
		$dbfile2 = OC::$SERVERROOT.'/tests/data/db_structure2.xml';

		$r = '_' . \OC::$server->getSecureRandom()->getMediumStrengthGenerator()->
			generate(4, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS) . '_';
		$content = file_get_contents( $dbfile );
		$content = str_replace( '*dbprefix*', '*dbprefix*'.$r, $content );
		file_put_contents( $this->schema_file, $content );
		$content = file_get_contents( $dbfile2 );
		$content = str_replace( '*dbprefix*', '*dbprefix*'.$r, $content );
		file_put_contents( $this->schema_file2, $content );

		$this->table1 = $r.'cntcts_addrsbks';
		$this->table2 = $r.'cntcts_cards';
	}

	protected function tearDown() {
		unlink($this->schema_file);
		unlink($this->schema_file2);

		parent::tearDown();
	}

	// everything in one test, they depend on each other
	/**
	 * @medium
	 */
	public function testSchema() {
		$platform = \OC_DB::getConnection()->getDatabasePlatform();
		if ($platform instanceof \Doctrine\DBAL\Platforms\SQLServerPlatform) {
			$this->markTestSkipped("Test not relevant on MSSQL");
		}
		$this->doTestSchemaCreating();
		$this->doTestSchemaChanging();
		$this->doTestSchemaDumping();
		$this->doTestSchemaRemoving();
	}

	public function doTestSchemaCreating() {
		OC_DB::createDbFromStructure($this->schema_file);
		$this->assertTableExist($this->table1);
		$this->assertTableExist($this->table2);
	}

	public function doTestSchemaChanging() {
		OC_DB::updateDbFromStructure($this->schema_file2);
		$this->assertTableExist($this->table2);
	}

	public function doTestSchemaDumping() {
		$outfile = 'static://db_out.xml';
		OC_DB::getDbStructure($outfile);
		$content = file_get_contents($outfile);
		$this->assertContains($this->table1, $content);
		$this->assertContains($this->table2, $content);
	}

	public function doTestSchemaRemoving() {
		OC_DB::removeDBStructure($this->schema_file);
		$this->assertTableNotExist($this->table1);
		$this->assertTableNotExist($this->table2);
	}

	public function testSchemaUnchanged() {
		$dbfile = OC::$SERVERROOT.'/db_structure.xml';
		$schema_file = 'static://live_db_scheme';

		$randomPrefix = $this->getUniqueID('', 4) . '_';
		$content = file_get_contents($dbfile);
		// Add prefix to index names to make them unique
		$content = str_replace('<name>', '<name>*dbprefix*', $content);
		$content = str_replace('*dbprefix**dbprefix*', '*dbprefix*', $content);
		$content = str_replace('*dbprefix*', 'oc_' . $randomPrefix, $content);
		file_put_contents($schema_file, $content);

		// The method OC_DB::tableExists() adds the prefix itself
		$this->assertTableNotExist($randomPrefix . 'filecache');
		\OC_DB::createDbFromStructure($schema_file);
		$this->assertTableExist($randomPrefix . 'filecache');
		\OC_DB::updateDbFromStructure($schema_file);
		$this->assertTableExist($randomPrefix . 'filecache');
		\OC_DB::removeDBStructure($schema_file);
		$this->assertTableNotExist($randomPrefix . 'filecache');

		unlink($schema_file);
		$this->assertTrue(true, 'Asserting that no error occurred when updating with the same schema that is already installed');
	}

	/**
	 * @param string $table
	 */
	public function assertTableExist($table) {
		$this->assertTrue(OC_DB::tableExists($table), 'Table ' . $table . ' does not exist');
	}

	/**
	 * @param string $table
	 */
	public function assertTableNotExist($table) {
		$platform = \OC_DB::getConnection()->getDatabasePlatform();
		if ($platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform) {
			// sqlite removes the tables after closing the DB
			$this->assertTrue(true);
		} else {
			$this->assertFalse(OC_DB::tableExists($table), 'Table ' . $table . ' exists.');
		}
	}
}
