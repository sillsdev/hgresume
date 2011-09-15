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

	function testAssemble_FilesInDir_FilesConcatenated() {
		$bundle = new BundleHelper("id123");
		$dir = $bundle->getAssemblyDir();
		file_put_contents("$dir/2.chunk", " brown fox");
		file_put_contents("$dir/1.chunk", "The quick");
		file_put_contents("$dir/3.chunk", " jumped...");
		$bundleFilename = $bundle->assemble();
		$this->assertEqual("The quick brown fox jumped...", file_get_contents($bundleFilename));
		$bundle->cleanUpPush();
	}
}

?>
