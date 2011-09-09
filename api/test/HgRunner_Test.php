<?php
require_once(dirname(__FILE__) . '/testconfig.php');
require_once(SimpleTestPath . '/autorun.php');
require_once("HgRepoTestEnvironment.php");
require_once(SourcePath . "/HgRunner.php");

class TestOfHgRunner extends UnitTestCase {

	var $testEnvironment;

	function setUp() {
		$this->testEnvironment = new HgRepoTestEnvironment();
	}

	function tearDown() {
		$this->testEnvironment->dispose();
	}

	function testUnbundle_BundleFileExists_BundleIsApplied() {
		$bundleFile = TestPath . "/data/sample.bundle";
		$repoPath = $this->testEnvironment->getPath();
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");

		// this file will be present after the bundle is applied
		$successFile = "$repoPath/bundlesuccess.txt";

		// precondition
		$this->assertTrue(file_exists($bundleFile));
		$this->assertFalse(file_exists($successFile));

		// check for success file
		$hg = new HgRunner($repoPath);
		$hg->unbundle($bundleFile);
		$this->assertTrue(file_exists($successFile));
	}

	function testMakeBundle_HgRepoExistsWithSuccessFile_BundleFileEqualsSampleBundleFile() {
		$referenceBundleFile = TestPath . "/data/sample.bundle";
		$repoPath = $this->testEnvironment->getPath();
		$bundleFile = "$repoPath/bundle";
		$successFile = "$repoPath/bundlesuccess.txt";
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo2.zip");

		// precondition
		$this->assertTrue(file_exists($successFile));
		$this->assertFalse(file_exists($bundleFile));

		// compare generated bundle with what we expect
		$hg = new HgRunner($repoPath);
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$hg->makeBundle($hash, $bundleFile);
		$this->assertEqual(filesize($bundleFile), filesize($referenceBundleFile));

	}
}



?>