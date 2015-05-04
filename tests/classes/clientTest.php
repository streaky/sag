<?php

use \streaky\sag;

class clientTest extends \PHPUnit_Framework_TestCase {

	/**
	 * @var \streaky\sag\client
	 */
	private static $sag = null;

	public static $test_admin_user = "admin_test";
	public static $test_admin_pass = "admin_zIM99soUCx";

	public static $test_db = "sag-test-db";

	/**
	 * @var null Temporary storage for use between tests
	 */
	private static $temp = null;

	public function __construct($name = NULL, array $data = array(), $dataName = '') {
		parent::__construct($name, $data, $dataName);
		// TODO: Allow admin creds and test db to be passed from ENV
	}

	/**
	 * Test that if we try to connect to a port where couch shouldn't be that we get a conn refused
	 * (and that sag correctly throws an exception of the right type/message)
	 *
	 * @expectedException streaky\sag\exception\sag
	 * @expectedExceptionMessage cURL Error: Connection Refused
	 */
	public function testDuffPort() {
		$sag = new sag\client("127.0.0.1", 59999);
		$sag->getAllDatabases();
	}

	/**
	 * When we connect we should get a 200 OK from couch
	 */
	public function testConnect() {
		self::$sag = new sag\client("127.0.0.1", 5984);
		$response = self::$sag->getStats();
		$this->assertEquals(200, $response->status);
	}

	/**
	 * We haven't authed yet, if we can create a db here something is wrong
	 *
	 * @expectedException streaky\sag\exception\couch
	 * @expectedExceptionMessage Unauthorized (You are not a server admin)
	 */
	public function testCreateDbNoAuth() {
		self::$sag->createDatabase("sag-test-db");
	}

	public function testAuth() {
		$response = self::$sag->login(self::$test_admin_user, self::$test_admin_pass);
		// response just returns true, but check for completeness
		$this->assertEquals(true, $response);
	}

	public function testCreateDbAuthed() {
		try {
			self::$sag->deleteDatabase(self::$test_db);
		} catch(sag\exception\couch $ex) {
			// NOP: This db may not exist - we don't care..
		}
		$response = self::$sag->createDatabase(self::$test_db);
		$this->assertEquals(201, $response->status);
		self::$sag->setDatabase(self::$test_db);
		$db = self::$sag->currentDatabase();
		$this->assertEquals(self::$test_db, $db);
	}

	/**
	 * Fetch a doc that shouldn't exist yet
	 *
	 * @expectedException streaky\sag\exception\couch
	 * @expectedExceptionMessage Not_found (missing)
	 */
	public function testFetchDoesntExist() {
		self::$sag->get("foo");

	}

	public function testCreateDoc() {
		$doc = new \stdClass();
		$doc->content = "test123456";
		$response = self::$sag->put("foo", $doc);
		$this->assertEquals(201, $response->status);
	}

	public function testFetchRealDoc() {
		$response = self::$sag->get("foo");
		$this->assertEquals(200, $response->status);

		$doc = $response->body;

		$this->assertEquals("foo", $doc->_id);
		$this->assertEquals("1-", substr($doc->_rev, 0, 2));
		$this->assertEquals("test123456", $doc->content);

		// we're going to use this later for update and to create a conflict
		self::$temp = $doc;

	}

	public function testUpdateDoc() {
		$new = clone self::$temp;
		$new->content = "testupdated";
		$response = self::$sag->put($new->_id, $new);
		$this->assertEquals(201, $response->status);
		$doc = $response->body;
		$this->assertEquals("foo", $doc->id);
		$this->assertEquals("2-", substr($doc->rev, 0, 2));
	}

	/**
	 * This doc update should conflict, we're replacing a new rev (2) with an old rev (1)
	 *
	 * @expectedException streaky\sag\exception\couch
	 * @expectedExceptionMessage Conflict (Document update conflict)
	 */
	public function testUpdateConflict() {
		$response = self::$sag->put("foo", self::$temp);
	}
}

