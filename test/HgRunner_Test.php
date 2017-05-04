<?php

require_once(SRC_PATH . "/HgRunner.php");
require_once(SRC_PATH . "/BundleHelper.php");

class TestOfHgRunner extends PHPUnit_Framework_TestCase {

    var $testEnvironment;

    function setUp() {
        $this->testEnvironment = new HgRepoTestEnvironment();
    }

    function tearDown() {
        $this->testEnvironment->dispose();
    }

    function testPrequisite_usrbintime_fileexists() {
        $this->assertTrue(file_exists("/usr/bin/time"));
    }

    function testUnbundle_BundleFileExists_BundleIsApplied() {
        $bundleFile = TEST_PATH . "/data/sample.bundle";
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $repoPath = $this->testEnvironment->Path;

        // this file will be present after the bundle is applied
        $successFile = "$repoPath/bundlesuccess.txt";

        // precondition
        $this->assertTrue(file_exists($bundleFile));
        $this->assertFalse(file_exists($successFile));

        // check for success file
        $hg = new HgRunner($repoPath);
        $asyncRunner = $hg->unbundle($bundleFile);
        $asyncRunner->synchronize();
        $asyncRunner->cleanUp();
        $asyncRunner = $hg->update();
        $asyncRunner->synchronize();
        $this->assertTrue(file_exists($successFile));
    }

    function testMakeBundle_HgRepoExistsWithSuccessFile_BundleFileEqualsSampleBundleFile() {
        $referenceBundleFile = TEST_PATH . "/data/sample.bundle";
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo2.zip");
        $repoPath = $this->testEnvironment->Path;
        $bundleFilePath = "$repoPath/bundle";
        $successFile = "$repoPath/bundlesuccess.txt";

        // precondition
        $this->assertTrue(file_exists($successFile));
        $this->assertFalse(file_exists($bundleFilePath));

        // compare generated bundle with what we expect
        $hg = new HgRunner($repoPath);
        $hash = trim(file_get_contents(TEST_PATH . "/data/sample.bundle.hash"));
        $hg->makeBundleAndWaitUntilFinished($hash, $bundleFilePath, new AsyncRunner($bundleFilePath));
        $this->assertEquals(filesize($bundleFilePath), filesize($referenceBundleFile));
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage repo 'somerandompath' doesn't exist!
     */
    function testMakeBundle_RepoDoesNotExist_Throws() {
        $hg = new HgRunner("somerandompath");
    }

    function testMakeBundle_BadBaseHash_InvalidBundle() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $repoPath = $this->testEnvironment->Path;
        $hg = new HgRunner($repoPath);
        $bundleFilePath = $repoPath . '/bundle';
        $asyncRunner = $hg->makeBundleAndWaitUntilFinished('whateverhash', $bundleFilePath);
        $this->assertTrue(BundleHelper::bundleOutputHasErrors($asyncRunner->getOutput()));
    }
/*
    function testMakeBundle_noBundleFile_isValidBundle() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $repoPath = $this->testEnvironment->Path;
        $hg = new HgRunner($repoPath);
        $hash = trim(file_get_contents(TEST_PATH . "/data/sample.bundle.hash"));
        $hg->makeBundleAndWaitUntilFinished($hash, '', new AsyncRunner(''));
        // REVIEW There are no asserts in this test CP 2012-06
    }
*/

    /**
     * @expectedException Exception
     * @expectedExceptionMessage 'randomfilethatdoesntexist' is not a file!
     */
    function testUnbundle_noBundleFile_throws() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $repoPath = $this->testEnvironment->Path;
        $hg = new HgRunner($repoPath);
        $hg->unbundle('randomfilethatdoesntexist');
    }

    function testGetTip_IdExists_ReturnsHash() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $hg = new HgRunner($this->testEnvironment->Path);
        $this->assertEquals($hg->getTip(), trim(file_get_contents(TEST_PATH . "/data/sample.bundle.hash")));
    }

    function testIsValidBase_HashNotExist_False() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $hg = new HgRunner($this->testEnvironment->Path);
        $this->assertFalse($hg->IsValidBase('whateverhash'));
    }

    function testIsValidBase_EmptyHash_False() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $hg = new HgRunner($this->testEnvironment->Path);
        $this->assertFalse($hg->IsValidBase(array('')));
    }

    function testIsValidBase_HashExist_True() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $hg = new HgRunner($this->testEnvironment->Path);
        $hash = trim(file_get_contents(TEST_PATH . "/data/sample.bundle.hash"));
        $this->assertTrue($hg->IsValidBase($hash));
    }

    function testGetRevisions_Request2And5Exist_2Revisions() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $hg = new HgRunner($this->testEnvironment->Path);
        $revisions = explode("\n", rtrim(file_get_contents(TEST_PATH . "/data/sample.revision.list"), "\n"));
        $this->assertEquals($hg->getRevisions(0,2), array_slice($revisions, 0, 2));
    }

    function testGetRevisions_Request50And5Exist_5Revisions() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $hg = new HgRunner($this->testEnvironment->Path);
        $revisions = explode("\n", rtrim(file_get_contents(TEST_PATH . "/data/sample.revision.list"), "\n"));
        $this->assertEquals($hg->getRevisions(0,50), $revisions);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage quantity parameter must be larger than 0
     */
    function testGetRevisions_Request0_Throws() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $hg = new HgRunner($this->testEnvironment->Path);
        $hg->getRevisions(0,0);
    }

    function testGetRevisions_Offset2Request2_2Revisions() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $hg = new HgRunner($this->testEnvironment->Path);
        $revisions = explode("\n", rtrim(file_get_contents(TEST_PATH . "/data/sample.revision.list"), "\n"));
        $this->assertEquals($hg->getRevisions(2,2), array_slice($revisions, 2, 2));
    }

    function testGetRevisions_InitializedRepoWIthZeroCHangesets_ReturnsZero() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/emptyHgRepo.zip");
        $hg = new HgRunner($this->testEnvironment->Path);
        $this->assertEquals($hg->getRevisions(0,1), array("0:"));
    }

    function testAddAndCheckInFile_AddFile_TipIsDifferent() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $hg = new HgRunner($this->testEnvironment->Path);
        $filename = "fileToAdd.txt";
        file_put_contents($this->testEnvironment->Path . "/" . $filename, "sample data to add");
        $beforeTip = $hg->getTip();
        $hg->addAndCheckInFile($filename);
        $this->assertNotEquals($beforeTip, $hg->getTip());
    }
}

?>
