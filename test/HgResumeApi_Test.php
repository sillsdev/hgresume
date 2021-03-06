<?php

use Lib\HgResumeApi;
use Lib\HgResumeResponse;
use Lib\HgRunner;

class TestOfHgResumeAPI extends PHPUnit_Framework_TestCase {
    /** @var HgRepoTestEnvironment */
    private $testEnvironment;

    /** @var HgResumeApi */
    private $api;

    function setUp() {
        $this->testEnvironment = new HgRepoTestEnvironment();
        $this->api = new HgResumeApi($this->testEnvironment->BasePath);
    }

    function tearDown() {
        $this->testEnvironment->dispose();
    }

    // finishPullBundle is a wrapper for BundleHelper->cleanUpPull, and that is already tested

    // finishPushBundle is a wrapper for BundleHelper->cleanUpPush, and that is already tested

    function testPushBundleChunk_BogusId_UnknownCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $response = $this->api->pushBundleChunk('fakeid', 10000, 0, 'chunkData', $transId);
        $this->assertEquals(HgResumeResponse::UNKNOWNID, $response->Code);
    }

    function testPushBundleChunk_EmptyId_UnknownCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $response = $this->api->pushBundleChunk('', 10000, 0, 'chunkData', $transId);
        $this->assertEquals(HgResumeResponse::UNKNOWNID, $response->Code);
    }

    function testPushBundleChunk_InvalidOffset_FailCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $chunkData = 'chunkData';
        $transId = __FUNCTION__;
        $response = $this->api->pushBundleChunk('sampleHgRepo', 1000, 2000, $chunkData, $transId);
        $this->assertEquals(HgResumeResponse::FAIL, $response->Code);
    }

    function testPushBundleChunk_NoData_FailCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $chunkData = '';
        $transId = __FUNCTION__;
        $response = $this->api->pushBundleChunk('sampleHgRepo', 1000, 0, $chunkData, $transId);
        $this->assertEquals(HgResumeResponse::FAIL, $response->Code);
    }

    function testPushBundleChunk_InvalidBundleSize_FailCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $chunkData = 'someData';
        $transId = __FUNCTION__;
        $response = $this->api->pushBundleChunk('sampleHgRepo', 'invalid', 0, $chunkData, $transId);
        $this->assertEquals(HgResumeResponse::FAIL, $response->Code);
    }

    function testPushBundleChunk_DataTooLarge_FailCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $chunkData = 'someDataLargerThan 10 bytes';
        $response = $this->api->pushBundleChunk('sampleHgRepo', 10, 0, $chunkData, $transId);
        $this->assertEquals(HgResumeResponse::FAIL, $response->Code);
    }

    function testPushBundleChunk_ChunkSent_ReceivedCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $chunkData = 'someChunkData';
        $this->api->finishPushBundle($transId); // clear out api
        $response = $this->api->pushBundleChunk('sampleHgRepo', 100, 0, $chunkData, $transId);
        $this->assertEquals(HgResumeResponse::RECEIVED, $response->Code);
    }

    function testPushBundleChunk_AllChunksSent_SuccessCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $this->api->finishPushBundle($transId);

        $bundleData = file_get_contents(TEST_PATH . "/data/sample.bundle");
        $bundleSize = mb_strlen($bundleData, "8bit");
        $chunkSize = 50;
        for ($offset = 0; $offset < $bundleSize; $offset+=$chunkSize) {

            $chunkData = mb_substr($bundleData, $offset, $chunkSize, "8bit");
            $actualChunkSize = mb_strlen($chunkData, "8bit");
            $response = $this->api->pushBundleChunk('sampleHgRepo', $bundleSize, $offset, $chunkData, $transId);
            if ($actualChunkSize < $chunkSize) { // this is the end
                $this->assertEquals(HgResumeResponse::SUCCESS, $response->Code);
            } else { // we're not finished yet
                $this->assertEquals(HgResumeResponse::RECEIVED, $response->Code);
            }
        }
    }

    function testPushBundleChunk_AllChunksSentButBadDataChunkSoBundleFails_ResetCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $this->api->finishPushBundle($transId);
        $response = $this->api->pushBundleChunk('sampleHgRepo', 15, 0, '12345', $transId);
        $this->assertEquals(HgResumeResponse::RECEIVED, $response->Code);
        $response = $this->api->pushBundleChunk('sampleHgRepo', 15, 5, '1234', $transId);
        $this->assertEquals(HgResumeResponse::RECEIVED, $response->Code);
        $response = $this->api->pushBundleChunk('sampleHgRepo', 15, 9, '1234', $transId);
        $this->assertEquals(HgResumeResponse::RECEIVED, $response->Code);
        $response = $this->api->pushBundleChunk('sampleHgRepo', 15, 13, '12', $transId);
        $this->assertEquals(HgResumeResponse::RESET, $response->Code);
    }

    // SOW = start of window; AKA offset
    function testPushBundleChunk_RequestedOffsetNotEqualToSOW_FailCodeReturnsSOW() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $this->api->finishPushBundle($transId);
        $this->api->pushBundleChunk('sampleHgRepo', 15, 0, '12345', $transId);
        $response = $this->api->pushBundleChunk('sampleHgRepo', 15, 10, '12345', $transId);
        $this->assertEquals(HgResumeResponse::FAIL, $response->Code);
        $this->assertEquals(5, $response->Values['sow']);
    }

    function testPushBundleChunk_PushWithOffsetZeroButSOWGreaterThanZero_ReceivedCodeReturnsSOW() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $this->api->finishPushBundle($transId);
        $this->api->pushBundleChunk('sampleHgRepo', 15, 0, '12345', $transId);
        $response = $this->api->pushBundleChunk('sampleHgRepo', 15, 0, '12', $transId);
        $this->assertEquals(HgResumeResponse::RECEIVED, $response->Code);
        $this->assertEquals(5, $response->Values['sow']);
    }

    function testPushBundleChunk_PushOneChunkThenRepoChanges_PushContinuesSuccessfully() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $hg = new HgRunner($this->testEnvironment->Path);
        $transId = __FUNCTION__;
        $this->api->finishPushBundle($transId);
        $filename = "fileToAdd.txt";
        $filePath = $this->testEnvironment->Path . "/" . $filename;
        file_put_contents($filePath, "sample data to add");

        $bundleData = file_get_contents(TEST_PATH . "/data/sample.bundle");
        $bundleSize = mb_strlen($bundleData, "8bit");
        $chunkSize = 50;
        for ($offset = 0; $offset < $bundleSize; $offset+=$chunkSize) {
            if ($offset == 50) {
                $hg->addAndCheckInFile($filename);
            }

            $chunkData = mb_substr($bundleData, $offset, $chunkSize, "8bit");
            $actualChunkSize = mb_strlen($chunkData, "8bit");
            $response = $this->api->pushBundleChunk('sampleHgRepo', $bundleSize, $offset, $chunkData, $transId);
            if ($actualChunkSize < $chunkSize) { // this is the end
                $this->assertEquals(HgResumeResponse::SUCCESS, $response->Code);
            } else { // we're not finished yet
                $this->assertEquals(HgResumeResponse::RECEIVED, $response->Code);
            }
        }
    }

    function testPushBundleChunk_InitializedRepoWithZeroChangesets_BundleSuccessfullyApplied() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/emptyHgRepo.zip");
        $hg = new HgRunner($this->testEnvironment->Path);
        $transId = __FUNCTION__;
        $this->api->finishPushBundle($transId);

        $bundleData = file_get_contents(TEST_PATH . "/data/sample_entire.bundle");
        $bundleSize = mb_strlen($bundleData, "8bit");
        $chunkSize = 50;
        for ($offset = 0; $offset < $bundleSize; $offset+=$chunkSize) {
            $chunkData = mb_substr($bundleData, $offset, $chunkSize, "8bit");
            $actualChunkSize = mb_strlen($chunkData, "8bit");
            $response = $this->api->pushBundleChunk('emptyHgRepo', $bundleSize, $offset, $chunkData, $transId);
            if ($actualChunkSize < $chunkSize) { // this is the end
                $this->assertEquals(HgResumeResponse::SUCCESS, $response->Code);
            } else { // we're not finished yet
                $this->assertEquals(HgResumeResponse::RECEIVED, $response->Code);
            }
        }
    }

    function testPullBundleChunk_EmptyId_UnknownCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $this->api->finishPullBundle($transId); // reset things on server
        $transId = __FUNCTION__;
        // REVIEW This doesn't need to use the wait version CP 2012-06
        $response = $this->api->pullBundleChunkInternal('', '', 0, 50, $transId, true);
        $this->assertEquals(HgResumeResponse::UNKNOWNID, $response->Code);
    }

    function testPullBundleChunk_BogusId_UnknownCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $this->api->finishPullBundle($transId); // reset things on server
        // REVIEW This doesn't need to use the wait version CP 2012-06
        $response = $this->api->pullBundleChunkInternal('fakeid', array(''), 0, 50, $transId, true);
        $this->assertEquals(HgResumeResponse::UNKNOWNID, $response->Code);
    }

    function testPullBundleChunk_InvalidHash_FailCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $this->api->finishPullBundle($transId); // reset things on server
        // REVIEW This doesn't need to use the wait version CP 2012-06
        $response = $this->api->pullBundleChunkInternal('sampleHgRepo', array('fakehash'), 0, 50, $transId, true);
        $this->assertEquals(HgResumeResponse::FAIL, $response->Code);
    }

    function testPullBundleChunk_ValidRequestButNoChanges_NoChangeCode() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $this->api->finishPullBundle($transId); // reset things on server
        $hash = trim(file_get_contents(TEST_PATH . "/data/sample.bundle.hash"));
        // REVIEW This doesn't need to use the wait version CP 2012-06
        $response = $this->api->pullBundleChunkInternal('sampleHgRepo', array($hash), 0, 50, $transId, true);
        $this->assertEquals(HgResumeResponse::NOCHANGE, $response->Code);
    }

    function testPullBundleChunk_OffsetZero_ValidData() {
        $offset = 0;
        $chunkSize = 50;
        $transId = __FUNCTION__;
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo2.zip");
        $this->api->finishPullBundle($transId); // reset things on server
        $hash = trim(file_get_contents(TEST_PATH . "/data/sample.bundle.hash"));
        $response = $this->api->pullBundleChunkInternal('sampleHgRepo2', array($hash), $offset, $chunkSize, $transId, true);
        $this->assertEquals(HgResumeResponse::SUCCESS, $response->Code);
        $wholeBundle = file_get_contents(TEST_PATH . "/data/sample.bundle");
        $expectedChunkData = mb_substr($wholeBundle, $offset, $chunkSize, "8bit");
        $this->assertEquals($chunkSize, $response->Values['chunkSize']);
        $this->assertEquals(mb_strlen($wholeBundle, "8bit"), $response->Values['bundleSize']);
    }

    function testPullBundleChunk_OffsetGreaterThanZeroAndNoBundleCreated_ResetResponse() {
        $offset = 50;
        $chunkSize = 50;
        $transId = __FUNCTION__;
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo2.zip");
        $this->api->finishPullBundle($transId); // reset things on server
        $hash = trim(file_get_contents(TEST_PATH . "/data/sample.bundle.hash"));
        $response = $this->api->pullBundleChunkInternal('sampleHgRepo2', array($hash), $offset, $chunkSize, $transId, true);
        $this->assertEquals(HgResumeResponse::RESET, $response->Code);
    }

    function testPullBundleChunk_OffsetEqualToBundleSize_SuccessCodeWithZeroChunkSize() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo2.zip");
        $transId = __FUNCTION__;
        $this->api->finishPullBundle($transId); // reset things on server
        $hash = trim(file_get_contents(TEST_PATH . "/data/sample.bundle.hash"));
        $wholeBundle = file_get_contents(TEST_PATH . "/data/sample.bundle");
        $bundleSize = mb_strlen($wholeBundle, "8bit");
        $offset = $bundleSize;
        $this->api->pullBundleChunkInternal('sampleHgRepo2', array($hash), 0, 1000, $transId, true);
        $response = $this->api->pullBundleChunkInternal('sampleHgRepo2', array($hash), $offset, 1000, $transId, true);
        $this->assertEquals(HgResumeResponse::SUCCESS, $response->Code);
        $this->assertEquals(0, $response->Values['chunkSize']);
        $this->assertEquals("", $response->Content);
    }

    function testPullBundleChunk_PullUntilFinished_AssembledBundleIsValid() {
        $offset = 0;
        $chunkSize = 50;
        $transId = __FUNCTION__;
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo2.zip");
        $this->api->finishPullBundle($transId); // reset things on server
        $hash = trim(file_get_contents(TEST_PATH . "/data/sample.bundle.hash"));

        $assembledBundle = '';
        $bundleSize = 1; // initialize the bundleSize; it will be overwritten after the first API call
        while ($offset < $bundleSize) {
            $response = $this->api->pullBundleChunkInternal('sampleHgRepo2', array($hash), $offset, $chunkSize, $transId, true);
            $this->assertEquals(HgResumeResponse::SUCCESS, $response->Code);
            $bundleSize = $response->Values['bundleSize'];
            $chunkSize = $response->Values['chunkSize'];
            $chunkData = $response->Content;
            $assembledBundle .= $chunkData;
            $offset += $chunkSize;
        }
        $wholeBundle = file_get_contents(TEST_PATH . "/data/sample.bundle");
        $this->assertEquals($wholeBundle, $assembledBundle);
    }

    function testPullBundleChunk_PullFromBaseRevisionUntilFinishedOnTwoBranchRepo_AssembledBundleIsValid() {
        $offset = 0;
        $chunkSize = 50;
        $transId = __FUNCTION__;
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sample2branchHgRepo.zip");
        $this->api->finishPullBundle($transId); // reset things on server
        $hash = trim(file_get_contents(TEST_PATH . "/data/sample2branch.hash"));

        $assembledBundle = '';
        $bundleSize = 1; // initialize the bundleSize; it will be overwritten after the first API call
        while ($offset < $bundleSize) {
            $response = $this->api->pullBundleChunkInternal('sample2branchHgRepo', array($hash), $offset, $chunkSize, $transId, true);
            $this->assertEquals(HgResumeResponse::SUCCESS, $response->Code);
            $bundleSize = $response->Values['bundleSize'];
            $chunkSize = $response->Values['chunkSize'];
            $chunkData = $response->Content;
            $assembledBundle .= $chunkData;
            $offset += $chunkSize;
        }
        $wholeBundle = file_get_contents(TEST_PATH . "/data/sample2branch.bundle");
        $this->assertEquals($wholeBundle, $assembledBundle);
    }

    function testPullBundleChunk_PullFromTwoBaseRevisionsUntilFinishedOnTwoBranchRepo_AssembledBundleIsValid() {
        $offset = 0;
        $chunkSize = 50;
        $transId = __FUNCTION__;
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sample2branchHgRepo.zip");
        $this->api->finishPullBundle($transId); // reset things on server
        $hashes = explode('|', trim(file_get_contents(TEST_PATH . "/data/sample2branch2base.hash")));

        $assembledBundle = '';
        $bundleSize = 1; // initialize the bundleSize; it will be overwritten after the first API call
        while ($offset < $bundleSize) {
            $response = $this->api->pullBundleChunkInternal('sample2branchHgRepo', $hashes, $offset, $chunkSize, $transId, true);
            $this->assertEquals(HgResumeResponse::SUCCESS, $response->Code);
            $bundleSize = $response->Values['bundleSize'];
            $chunkSize = $response->Values['chunkSize'];
            $chunkData = $response->Content;
            $assembledBundle .= $chunkData;
            $offset += $chunkSize;
        }
        $wholeBundle = file_get_contents(TEST_PATH . "/data/sample2branch2base.bundle");
        $this->assertEquals($wholeBundle, $assembledBundle);
    }

    function testGetRevisions_2BranchRepo_ReturnsTwoBranches() {
        $offset = 0;
        $chunkSize = 50;
        $transId = __FUNCTION__;
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sample2branchHgRepo.zip");
        $this->api->finishPullBundle($transId); // reset things on server
        $hashes = explode('|', trim(file_get_contents(TEST_PATH . "/data/sample2branch2tip.hash")));

        $response = $this->api->getRevisions('sample2branchHgRepo', $offset, $chunkSize);
        $this->assertEquals(HgResumeResponse::SUCCESS, $response->Code);
        $revisions = explode('|', $response->Content);
        $branchArray = array();
        foreach($revisions as $revision) {
            $hashAndBranch = explode(':', $revision);
            $branchArray[$hashAndBranch[1]] = 1;
        }
        $this->assertEquals(count($branchArray), 2);
    }

    function testPullBundleChunk_2BranchRepoNoChanges_ReturnsNoChange() {
        $offset = 0;
        $chunkSize = 500;
        $transId = __FUNCTION__;
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sample2branchHgRepo.zip");
        $this->api->finishPullBundle($transId); // reset things on server
        $hashes = explode('|', trim(file_get_contents(TEST_PATH . "/data/sample2branch2tip.hash")));

        $response = $this->api->pullBundleChunkInternal('sample2branchHgRepo', $hashes, $offset, $chunkSize, $transId, true);
        $this->assertEquals(HgResumeResponse::NOCHANGE, $response->Code);
    }

    function testPullBundleChunk_PullUntilFinishedThenRepoChanges_AssembledBundleIsValidAndResetCodeReceivedFromFinishPullBundle() {
        $offset = 0;
        $chunkSize = 50;
        $transId = __FUNCTION__;
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo2.zip");
        $this->api->finishPullBundle($transId); // reset things on server
        $hash = trim(file_get_contents(TEST_PATH . "/data/sample.bundle.hash"));

        $hg = new HgRunner($this->testEnvironment->Path);
        $filename = "fileToAdd.txt";
        $filePath = $this->testEnvironment->Path . "/" . $filename;
        file_put_contents($filePath, "sample data to add");

        $assembledBundle = '';
        $ctr = 1;
        $bundleSize = 1; // initialize the bundleSize; it will be overwritten after the first API call
        while ($offset < $bundleSize) {
            if ($ctr == 3) {
                $hg->addAndCheckInFile($filename);
            }
            $response = $this->api->pullBundleChunkInternal('sampleHgRepo2', array($hash), $offset, $chunkSize, $transId, true);
            $this->assertEquals(HgResumeResponse::SUCCESS, $response->Code);
            $bundleSize = $response->Values['bundleSize'];
            $chunkSize = $response->Values['chunkSize'];
            $chunkData = $response->Content;

            $assembledBundle .= $chunkData;
            $offset += $chunkSize;
            $ctr++;
        }
        $wholeBundle = file_get_contents(TEST_PATH . "/data/sample.bundle");
        $this->assertEquals($wholeBundle, $assembledBundle);
        $finishResponse = $this->api->finishPullBundle($transId);
        $this->assertEquals(HgResumeResponse::RESET, $finishResponse->Code);
    }

    function testPullBundleChunk_longMakeBundle_InProgressCode() {
        $transId = __FUNCTION__;
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleLargeBundleHgRepo.zip");
        $this->api->finishPullBundle($transId); // reset things on server

        $response = $this->api->pullBundleChunk('sampleLargeBundleHgRepo', array("0"), 0, 10000000, $transId);
        $this->assertEquals(HgResumeResponse::INPROGRESS, $response->Code);
    }

    function testPullBundleChunk_BaseHashIsZero_ReturnsEntireRepoAsBundle() {
        $offset = 0;
        $chunkSize = 50;
        $transId = __FUNCTION__;
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo2.zip");
        $hash = "0";
        $this->api->finishPullBundle($transId); // reset things on server

        $assembledBundle = '';
        $bundleSize = 1; // initialize the bundleSize; it will be overwritten after the first API call
        while ($offset < $bundleSize) {
            $response = $this->api->pullBundleChunkInternal('sampleHgRepo2', array($hash), $offset, $chunkSize, $transId, true);
            $this->assertEquals(HgResumeResponse::SUCCESS, $response->Code);
            $bundleSize = $response->Values['bundleSize'];
            $chunkSize = $response->Values['chunkSize'];
            $chunkData = $response->Content;
            $assembledBundle .= $chunkData;
            $offset += $chunkSize;
        }
        $wholeBundle = file_get_contents(TEST_PATH . "/data/sample_entire.bundle");
        $this->assertEquals($wholeBundle, $assembledBundle);
    }

    function testPullBundleChunk_EmptyRepositoryReturnsNoChanges() {
        $offset = 0;
        $chunkSize = 50;
        $transId = __FUNCTION__;
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/emptyHgRepo.zip");
        $this->api->finishPullBundle($transId); // reset things on server
        $response = $this->api->pullBundleChunkInternal('emptyHgRepo', array("0"), $offset, $chunkSize, $transId, true);
        $this->assertEquals(HgResumeResponse::NOCHANGE, $response->Code);
    }

    function testIsAvailable_noMessageFile_SuccessCode() {
        $messageFilePath = SourcePath . "/maintenance_message.txt";
        $this->assertFalse(file_exists($messageFilePath));
        $response = $this->api->isAvailable();
        $this->assertEquals(HgResumeResponse::SUCCESS, $response->Code);
    }

    function testIsAvailable_MessageFileExists_FailCodeWithMessage() {
        $messageFilePath = SourcePath . "/maintenance_message.txt";
        $message = "Server is down for maintenance.";
        file_put_contents($messageFilePath, $message);
        $this->assertTrue(file_exists($messageFilePath));
        $response = $this->api->isAvailable();
        $this->assertEquals(HgResumeResponse::NOTAVAILABLE, $response->Code);
        $this->assertEquals($message, $response->Content);
    }

    // as it turns out, there are two kinds of unrelated bundles, one which upon running "hg incoming" returns "unknown parent" and a different kind of bundle which returns "parent:  -1"

    function testPushBundleChunk_pushBundleFromUnrelatedRepo1_FailCodeWithMessage() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $this->api->finishPushBundle($transId);

        $bundleData = file_get_contents(TEST_PATH . "/data/unrelated.bundle");
        $bundleSize = mb_strlen($bundleData, "8bit");
        //$chunkData = mb_substr($bundleData, 0, 50, "8bit");
        //$actualChunkSize = mb_strlen($chunkData, "8bit");
        $response = $this->api->pushBundleChunk('sampleHgRepo', $bundleSize, 0, $bundleData, $transId);
        $this->assertEquals(HgResumeResponse::FAIL, $response->Code);
    }

    function testPushBundleChunk_pushBundleFromUnrelatedRepo2_FailCodeWithMessage() {
        $this->testEnvironment->makeRepo(TEST_PATH . "/data/sampleHgRepo.zip");
        $transId = __FUNCTION__;
        $this->api->finishPushBundle($transId);

        $bundleData = file_get_contents(TEST_PATH . "/data/unrelated2.bundle");
        $bundleSize = mb_strlen($bundleData, "8bit");
        //$chunkData = mb_substr($bundleData, 0, 50, "8bit");
        //$actualChunkSize = mb_strlen($chunkData, "8bit");
        $response = $this->api->pushBundleChunk('sampleHgRepo', $bundleSize, 0, $bundleData, $transId);
        $this->assertEquals(HgResumeResponse::FAIL, $response->Code);
    }
}
