<?php

require_once(dirname(__FILE__) . '/testconfig.php');
require_once(SimpleTestPath . '/autorun.php');
require_once(SourcePath . "/BundleHelper.php");

class TestOfBundleHelper extends UnitTestCase {

	function testGetAssemblyDir_NewBundle_CreatesDir() {
		$bundle = new BundleHelper("id123");
		$path = $bundle->getAssemblyDir();
		$this->assertTrue(is_dir($path));
	}

	function testGetPullDir_NewBundle_CreatesDir() {
		$bundle = new BundleHelper("id123");
		$path = $bundle->getPullDir();
		$this->assertTrue(is_dir($path));
	}

	function testCleanUpPush_BundleWithChunkFiles_DeletesDir() {
		$bundle = new BundleHelper("id123");
		$path = $bundle->getAssemblyDir();
		$this->assertTrue(is_dir($path));
		file_put_contents("$path/sample.chunk", "sample data");
		file_put_contents("$path/sample2.chunk", "more sample data");
		$bundle->cleanUpPush();
		$this->assertFalse(is_dir($path));
	}

	function testCleanUpPull_BundleFileExists_DeletesBundleFile() {
		$bundle = new BundleHelper("id123");
		$path = $bundle->getPullDir();
		$this->assertTrue(is_dir($path));
		$bundleFilename = $bundle->getPullFilePath();
		file_put_contents($bundleFilename, "bundle data");
		$bundle->cleanUpPull();
		$this->assertFalse(is_file($bundleFilename));
	}

	function testConstructor_TransIdIsAlphaNumeric_NoException() {
		$bundle = new BundleHelper("thisIsAlphaNumeric");
	}

	function testConstructor_TransIdCodeInjection_ThrowsException() {
		$this->expectException();
		$bundle = new BundleHelper("id; echo \"bad script!\"");
	}

	function testGetOffset_Unset_ReturnsZero() {
		$transId = "id123";
		$bundle = new BundleHelper($transId);
		$this->assertEqual(0, $bundle->getOffset());
	}

	function testSetGetOffset_SetThenGet_GetReturnsValueThatWasSet() {
		$transId = "id123";
		$bundle = new BundleHelper($transId);
		$sow = 5023;
		$bundle->setOffset($sow);
		$this->assertEqual($sow, $bundle->getOffset());
	}

	function testSetGetHasProp_SetMultipleProps_GetPropsOkAndVerifyHasPropsOk() {
		$transId = "id123";
		$bundle = new BundleHelper($transId);
		$this->assertFalse($bundle->hasProp("tip"));
		$bundle->setProp("tip", "7890");
		$this->assertTrue($bundle->hasProp("tip"));
		$bundle->setProp("repoId", "myRepo");
		$this->assertEqual("7890", $bundle->getProp("tip"));
		$this->assertEqual("myRepo", $bundle->getProp("repoId"));
	}
}

?>
