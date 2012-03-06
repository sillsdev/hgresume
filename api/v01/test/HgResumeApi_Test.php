<?php
require_once(dirname(__FILE__) . '/testconfig.php');
require_once(SimpleTestPath . '/autorun.php');
require_once(SourcePath . "/HgResumeApi.php");
require_once(SourcePath . "/HgResumeResponse.php");
require_once(TestPath . "/HgRepoTestEnvironment.php");

class TestOfHgResumeAPI extends UnitTestCase {

	var $testEnvironment;
	var $api;

	function setUp() {
		$this->testEnvironment = new HgRepoTestEnvironment();
		$this->api = new HgResumeAPI($this->testEnvironment->BasePath);
	}

	function tearDown() {
		$this->testEnvironment->dispose();
	}

	function testGetTip_IdExists_ReturnsSuccessCodeAndHash() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$repoId = $this->testEnvironment->RepoId;
		$response = $this->api->getTip($repoId);
		$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);

		$this->assertEqual($response->Values['Tip'], trim(file_get_contents(TestPath . "/data/sample.bundle.hash")));
	}

	function testGetTip_IdNotExistsExists_ReturnsFailCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$response = $this->api->getTip('invalidid');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
	}

	// finishPullBundle is a wrapper for BundleHelper->cleanUpPull, and that is already tested

	// finishPushBundle is a wrapper for BundleHelper->cleanUpPush, and that is already tested

	function testPushBundleChunk_BogusId_UnknownCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$response = $this->api->pushBundleChunk('fakeid', 10000, 0, 'chunkData', 'id123');
		$this->assertEqual(HgResumeResponse::UNKNOWNID, $response->Code);
	}

	function testPushBundleChunk_EmptyId_UnknownCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$response = $this->api->pushBundleChunk('', 10000, 0, 'chunkData', 'id123');
		$this->assertEqual(HgResumeResponse::UNKNOWNID, $response->Code);
	}

	function testPushBundleChunk_InvalidOffset_FailCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$chunkData = 'chunkData';
		$response = $this->api->pushBundleChunk('sampleHgRepo', 1000, 2000, $chunkData, 'id123');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
	}

	function testPushBundleChunk_NoData_FailCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$chunkData = '';
		$response = $this->api->pushBundleChunk('sampleHgRepo', 1000, 0, $chunkData, 'id123');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
	}

	function testPushBundleChunk_InvalidBundleSize_FailCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$chunkData = 'someData';
		$response = $this->api->pushBundleChunk('sampleHgRepo', 'invalid', 0, $chunkData, 'id123');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
	}

	function testPushBundleChunk_DataTooLarge_FailCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$chunkData = 'someDataLargerThan 10 bytes';
		$response = $this->api->pushBundleChunk('sampleHgRepo', 10, 0, $chunkData, 'id123');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
	}

	function testPushBundleChunk_ChunkSent_ReceivedCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$chunkData = 'someChunkData';
		$this->api->finishPushBundle('id123'); // clear out api
		$response = $this->api->pushBundleChunk('sampleHgRepo', 100, 0, $chunkData, 'id123');
		$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
	}

	function testPushBundleChunk_AllChunksSent_SuccessCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$this->api->finishPushBundle('id123');

		$bundleData = file_get_contents(TestPath . "/data/sample.bundle");
		$bundleSize = mb_strlen($bundleData, "8bit");
		$chunkSize = 50;
		for ($offset = 0; $offset < $bundleSize; $offset+=$chunkSize) {

			$chunkData = mb_substr($bundleData, $offset, $chunkSize, "8bit");
			$actualChunkSize = mb_strlen($chunkData, "8bit");
			$response = $this->api->pushBundleChunk('sampleHgRepo', $bundleSize, $offset, $chunkData, 'id123');
			if ($actualChunkSize < $chunkSize) { // this is the end
				$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);
			} else { // we're not finished yet
				$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
			}
		}
	}

	function testPushBundleChunk_AllChunksSentButBadDataChunkSoBundleFails_ResetCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$this->api->finishPushBundle('id123');
		$response = $this->api->pushBundleChunk('sampleHgRepo', 15, 0, '12345', 'id123');
		$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
		$response = $this->api->pushBundleChunk('sampleHgRepo', 15, 5, '1234', 'id123');
		$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
		$response = $this->api->pushBundleChunk('sampleHgRepo', 15, 9, '1234', 'id123');
		$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
		$response = $this->api->pushBundleChunk('sampleHgRepo', 15, 13, '12', 'id123');
		$this->assertEqual(HgResumeResponse::RESET, $response->Code);
	}

	// SOW = start of window; AKA offset
	function testPushBundleChunk_RequestedOffsetNotEqualToSOW_FailCodeReturnsSOW() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$transId = 'id123';
		$this->api->finishPushBundle($transId);
		$this->api->pushBundleChunk('sampleHgRepo', 15, 0, '12345', $transId);
		$response = $this->api->pushBundleChunk('sampleHgRepo', 15, 10, '12345', $transId);
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
		$this->assertEqual(5, $response->Values['sow']);
	}

	function testPushBundleChunk_PushWithOffsetZeroButSOWGreaterThanZero_ReceivedCodeReturnsSOW() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$transId = 'id123';
		$this->api->finishPushBundle($transId);
		$this->api->pushBundleChunk('sampleHgRepo', 15, 0, '12345', $transId);
		$response = $this->api->pushBundleChunk('sampleHgRepo', 15, 0, '12', $transId);
		$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
		$this->assertEqual(5, $response->Values['sow']);
	}

	function testPushBundleChunk_PushOneChunkThenRepoChanges_PushContinuesSuccessfully() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$transId = 'id123';
		$this->api->finishPushBundle($transId);
		$filename = "fileToAdd.txt";
		$filePath = $this->testEnvironment->Path . "/" . $filename;
		file_put_contents($filePath, "sample data to add");

		$bundleData = file_get_contents(TestPath . "/data/sample.bundle");
		$bundleSize = mb_strlen($bundleData, "8bit");
		$chunkSize = 50;
		for ($offset = 0; $offset < $bundleSize; $offset+=$chunkSize) {
			if ($offset == 50) {
				$hg->addAndCheckInFile($filename);
			}

			$chunkData = mb_substr($bundleData, $offset, $chunkSize, "8bit");
			$actualChunkSize = mb_strlen($chunkData, "8bit");
			$response = $this->api->pushBundleChunk('sampleHgRepo', $bundleSize, $offset, $chunkData, 'id123');
			if ($actualChunkSize < $chunkSize) { // this is the end
				$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);
			} else { // we're not finished yet
				$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
			}
		}
	}

	function testPushBundleChunk_InitializedRepoWithZeroChangesets_BundleSuccessfullyApplied() {
		$this->testEnvironment->makeRepo(TestPath . "/data/emptyHgRepo.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$transId = 'id123';
		$this->api->finishPushBundle($transId);

		$bundleData = file_get_contents(TestPath . "/data/sample_entire.bundle");
		$bundleSize = mb_strlen($bundleData, "8bit");
		$chunkSize = 50;
		for ($offset = 0; $offset < $bundleSize; $offset+=$chunkSize) {
			$chunkData = mb_substr($bundleData, $offset, $chunkSize, "8bit");
			$actualChunkSize = mb_strlen($chunkData, "8bit");
			$response = $this->api->pushBundleChunk('emptyHgRepo', $bundleSize, $offset, $chunkData, 'id123');
			if ($actualChunkSize < $chunkSize) { // this is the end
				$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);
			} else { // we're not finished yet
				$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
			}
		}
	}








	function testPullBundleChunk_EmptyId_UnknownCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$response = $this->api->pullBundleChunk('', '', 0, 50, 'id123');
		$this->assertEqual(HgResumeResponse::UNKNOWNID, $response->Code);
	}

	function testPullBundleChunk_BogusId_UnknownCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$response = $this->api->pullBundleChunk('fakeid', '', 0, 50, 'id123');
		$this->assertEqual(HgResumeResponse::UNKNOWNID, $response->Code);
	}

	function testPullBundleChunk_InvalidHash_FailCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$response = $this->api->pullBundleChunk('sampleHgRepo', 'fakehash', 0, 50, 'id123');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
	}

	function testPullBundleChunk_ValidRequestButNoChanges_NoChangeCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$response = $this->api->pullBundleChunk('sampleHgRepo', $hash, 0, 50, 'id123');
		$this->assertEqual(HgResumeResponse::NOCHANGE, $response->Code);
	}

	function testPullBundleChunk_OffsetZero_ValidData() {
		$offset = 0;
		$chunkSize = 50;
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo2.zip");
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$this->api->finishPullBundle('id123'); // reset things on server
		$response = $this->api->pullBundleChunk('sampleHgRepo2', $hash, $offset, $chunkSize, 'id123');
		$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);
		$wholeBundle = file_get_contents(TestPath . "/data/sample.bundle");
		$expectedChunkData = mb_substr($wholeBundle, $offset, $chunkSize, "8bit");
		$this->assertEqual($chunkSize, $response->Values['chunkSize']);
		$this->assertEqual(mb_strlen($wholeBundle, "8bit"), $response->Values['bundleSize']);
	}

	function testPullBundleChunk_OffsetEqualToBundleSize_SuccessCodeWithZeroChunkSize() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo2.zip");
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$this->api->finishPullBundle('id123'); // reset things on server
		$wholeBundle = file_get_contents(TestPath . "/data/sample.bundle");
		$bundleSize = mb_strlen($wholeBundle, "8bit");
		$offset = $bundleSize;
		$response = $this->api->pullBundleChunk('sampleHgRepo2', $hash, $offset, 1000, 'id123');
		$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);
		$this->assertEqual(0, $response->Values['chunkSize']);
		$this->assertEqual("", $response->Content);
	}

	function testPullBundleChunk_MiddleOffset_ValidData() {
		$offset = 100;
		$chunkSize = 100;
		$transId = 'id123';
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo2.zip");
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$this->api->finishPullBundle($transId); // reset things on server
		$response = $this->api->pullBundleChunk('sampleHgRepo2', $hash, $offset, $chunkSize, $transId);
		$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);
		$wholeBundle = file_get_contents(TestPath . "/data/sample.bundle");
		$expectedChunkData = mb_substr($wholeBundle, $offset, $chunkSize, "8bit");
		$this->assertEqual($chunkSize, $response->Values['chunkSize']);
		$this->assertEqual($transId, $response->Values['transId']);
		$this->assertEqual(mb_strlen($wholeBundle, "8bit"), $response->Values['bundleSize']);
	}

	function testPullBundleChunk_PullUntilFinished_AssembledBundleIsValid() {
		$offset = 0;
		$chunkSize = 50;
		$transId = 'id123';
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo2.zip");
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$this->api->finishPullBundle($transId); // reset things on server

		$assembledBundle = '';
		$bundleSize = 1; // initialize the bundleSize; it will be overwritten after the first API call
		while (mb_strlen($assembledBundle) < $bundleSize) {
			$response = $this->api->pullBundleChunk('sampleHgRepo2', $hash, $offset, $chunkSize, $transId);
			$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);
			$bundleSize = $response->Values['bundleSize'];
			$chunkSize = $response->Values['chunkSize'];
			$chunkData = $response->Content;
			$assembledBundle .= $chunkData;
			$offset += $chunkSize;
		}
		$wholeBundle = file_get_contents(TestPath . "/data/sample.bundle");
		$this->assertEqual($wholeBundle, $assembledBundle);
	}

	function testPullBundleChunk_PullUntilFinishedThenRepoChanges_AssembledBundleIsValidAndResetCodeReceivedFromFinishPullBundle() {
		$offset = 0;
		$chunkSize = 50;
		$transId = 'id123';
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo2.zip");
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$this->api->finishPullBundle($transId); // reset things on server

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
			$response = $this->api->pullBundleChunk('sampleHgRepo2', $hash, $offset, $chunkSize, $transId);
			$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);
			$bundleSize = $response->Values['bundleSize'];
			$chunkSize = $response->Values['chunkSize'];
			$chunkData = $response->Content;

			$assembledBundle .= $chunkData;
			$offset += $chunkSize;
			$ctr++;
		}
		$wholeBundle = file_get_contents(TestPath . "/data/sample.bundle");
		$this->assertEqual($wholeBundle, $assembledBundle);
		$finishResponse = $this->api->finishPullBundle($transId);
		$this->assertEqual(HgResumeResponse::RESET, $finishResponse->Code);
	}

	function testIsAvailable_noMessageFile_SuccessCode() {
		$messageFilePath = $this->api->RepoBasePath . "/maintenance_message.txt";
		$this->assertFalse(file_exists($messageFilePath));
		$response = $this->api->isAvailable();
		$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);
	}

	function testIsAvailable_MessageFileExists_FailCodeWithMessage() {
		$messageFilePath = $this->api->RepoBasePath . "/maintenance_message.txt";
		$message = "Server is down for maintenance.";
		file_put_contents($messageFilePath, $message);
		$this->assertTrue(file_exists($messageFilePath));
		$response = $this->api->isAvailable();
		$this->assertEqual(HgResumeResponse::NOTAVAILABLE, $response->Code);
		$this->assertEqual($message, $response->Content);
	}

	function testPullBundleChunk_BaseHashIsZero_ReturnsEntireRepoAsBundle() {
		$offset = 0;
		$chunkSize = 50;
		$transId = 'id123';
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo2.zip");
		$hash = "0";
		$this->api->finishPullBundle($transId); // reset things on server

		$assembledBundle = '';
		$bundleSize = 1; // initialize the bundleSize; it will be overwritten after the first API call
		while (mb_strlen($assembledBundle) < $bundleSize) {
			$response = $this->api->pullBundleChunk('sampleHgRepo2', $hash, $offset, $chunkSize, $transId);
			$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);
			$bundleSize = $response->Values['bundleSize'];
			$chunkSize = $response->Values['chunkSize'];
			$chunkData = $response->Content;
			$assembledBundle .= $chunkData;
			$offset += $chunkSize;
		}
		$wholeBundle = file_get_contents(TestPath . "/data/sample_entire.bundle");
		//$wholeBundleSize = mb_strlen($wholeBundle, "8bit");
		$this->assertEqual($wholeBundle, $assembledBundle);
	}
}

?>