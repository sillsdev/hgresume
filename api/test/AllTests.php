<?php
require_once(dirname(__FILE__) . '/testconfig.php');
require_once(SimpleTestPath .  'autorun.php');

class AllTests extends TestSuite {
	function __construct() {
		parent::__construct();
		$this->addFile(TestPath . 'hgresumeapi_Test.php');
	}
}

?>
