<?php
require_once(dirname(__FILE__) . '/testconfig.php');
require_once(SimpleTestPath .  '/autorun.php');

class AllTests extends TestSuite {
	function __construct() {
		parent::__construct();
		$this->addFile(TestPath . '/BundleHelper_Test.php');
		//$this->addFile(TestPath . '/HgRunner_Test.php');
		//$this->addFile(TestPath . '/HgResumeAPI_Test.php');
	}
}

?>
