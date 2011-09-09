<?php
require_once(dirname(__FILE__) . '/testconfig.php');
require_once(SimpleTestPath . '/autorun.php');
require_once(SourcePath . "/HgResumeAPI.php");

class TestOfHgResumeAPI extends UnitTestCase {

	var $testEnvironment;

	function setUp() {
		//$this->testEnvironment = new HgRepoTestEnvironment();
	}

	function tearDown() {
		//$this->testEnvironment->dispose();
	}

	function testPushBundleChunk() {
	}
}

?>