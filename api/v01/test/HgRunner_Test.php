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
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$repoPath = $this->testEnvironment->Path;

		// this file will be present after the bundle is applied
		$successFile = "$repoPath/bundlesuccess.txt";

		// precondition
		$this->assertTrue(file_exists($bundleFile));
		$this->assertFalse(file_exists($successFile));

		// check for success file
		$hg = new HgRunner($repoPath);
		$hg->unbundle($bundleFile);
		$hg->update();
		$this->assertTrue(file_exists($successFile));
	}

	function testMakeBundle_HgRepoExistsWithSuccessFile_BundleFileEqualsSampleBundleFile() {
		$referenceBundleFile = TestPath . "/data/sample.bundle";
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo2.zip");
		$repoPath = $this->testEnvironment->Path;
		$bundleFile = "$repoPath/bundle";
		$successFile = "$repoPath/bundlesuccess.txt";

		// precondition
		$this->assertTrue(file_exists($successFile));
		$this->assertFalse(file_exists($bundleFile));

		// compare generated bundle with what we expect
		$hg = new HgRunner($repoPath);
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$hg->makeBundle($hash, $bundleFile);
		$this->assertEqual(filesize($bundleFile), filesize($referenceBundleFile));
	}

	function testMakeBundle_RepoDoesNotExist_Throws() {
		$this->expectException();
		$hg = new HgRunner("somerandompath");
	}

	function testMakeBundle_BadBaseHash_Throws() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$repoPath = $this->testEnvironment->Path;
		$hg = new HgRunner($repoPath);
		$this->expectException();
		$hg->makeBundle('whateverhash', "$repoPath/bundle");
	}

	function testMakeBundle_noBundleFile_throws() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$repoPath = $this->testEnvironment->Path;
		$hg = new HgRunner($repoPath);
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$this->expectException();
		$hg->makeBundle($hash, '');
	}

	function testUnBundle_noBundleFile_throws() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$repoPath = $this->testEnvironment->Path;
		$hg = new HgRunner($repoPath);
		$this->expectException();
		$hg->unbundle('randomfilethatdoesntexist');
	}

	function testGetTip_IdExists_ReturnsHash() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$this->assertEqual($hg->getTip(), trim(file_get_contents(TestPath . "/data/sample.bundle.hash")));
	}

	function testIsValidBase_HashNotExist_False() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$this->assertFalse($hg->IsValidBase('whateverhash'));
	}

	function testIsValidBase_EmptyHash_False() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$this->assertFalse($hg->IsValidBase(''));
	}

	function testIsValidBase_HashExist_True() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$this->assertTrue($hg->IsValidBase($hash));
	}

	function testGetRevisions_Request2And5Exist_2Revisions() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$revisions = explode("\n", rtrim(file_get_contents(TestPath . "/data/sample.revision.list"), "\n"));
		$this->assertEqual($hg->getRevisions(0,2), array_slice($revisions, 0, 2));
	}

	function testGetRevisions_Request50And5Exist_5Revisions() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$revisions = explode("\n", rtrim(file_get_contents(TestPath . "/data/sample.revision.list"), "\n"));
		$this->assertEqual($hg->getRevisions(0,50), $revisions);
	}

	function testGetRevisions_Request0_Throws() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$this->expectException();
		$hg->getRevisions(0,0);
	}

	function testGetRevisions_Offset2Request2_2Revisions() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$revisions = explode("\n", rtrim(file_get_contents(TestPath . "/data/sample.revision.list"), "\n"));
		$this->assertEqual($hg->getRevisions(2,2), array_slice($revisions, 2, 2));
	}

	function testAddAndCheckInFile_AddFile_TipIsDifferent() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$filename = "fileToAdd.txt";
		file_put_contents($this->testEnvironment->Path . "/" . $filename, "sample data to add");
		$beforeTip = $hg->getTip();
		$hg->addAndCheckInFile($filename);
		$this->assertNotEqual($beforeTip, $hg->getTip());
	}
}

?>