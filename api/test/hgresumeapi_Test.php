<?php
require_once(dirname(__FILE__) . '/testconfig.php');
require_once(SimpleTestPath . '/autorun.php');
require_once(SourcePath . "/HgResumeAPI.php");
require_once(SourcePath . "/HgResumeResponse.php");

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
		$response = $this->api->pushBundleChunk('fakeid', 'fakehash', 10000, 'chunkChecksum', 0, 'chunkData', 'id123');
		$this->assertEqual(HgResumeResponse::UNKNOWNID, $response->Code);
	}

	function testPushBundleChunk_DataBadChecksum_ResendCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$response = $this->api->pushBundleChunk('sampleHgRepo', 'fakehash', 10000, 'badCheckSum', 0, 'chunkData', 'id123');
		$this->assertEqual(HgResumeResponse::RESEND, $response->Code);
	}

	function testPushBundleChunk_InvalidOffset_FailCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$chunkData = 'chunkData';
		$checksum = md5($chunkData);
		$response = $this->api->pushBundleChunk('sampleHgRepo', '', 1000, $checksum, 2000, $chunkData, 'id123');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
	}

	function testPushBundleChunk_NoData_FailCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$chunkData = '';
		$checksum = md5($chunkData);
		$response = $this->api->pushBundleChunk('sampleHgRepo', '', 1000, $checksum, 0, $chunkData, 'id123');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
	}

	function testPushBundleChunk_InvalidBaseHash_FailCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$chunkData = 'someData';
		$checksum = md5($chunkData);
		$response = $this->api->pushBundleChunk('sampleHgRepo', 'fakehash', 1000, $checksum, 0, $chunkData, 'id123');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
	}

	function testPushBundleChunk_InvalidBundleSize_FailCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$chunkData = 'someData';
		$checksum = md5($chunkData);
		$response = $this->api->pushBundleChunk('sampleHgRepo', $hash, 'invalid', $checksum, 0, $chunkData, 'id123');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
	}

	function testPushBundleChunk_DataTooLarge_FailCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$chunkData = 'someDataLargerThan 10 bytes';
		$checksum = md5($chunkData);
		$response = $this->api->pushBundleChunk('sampleHgRepo', $hash, 10, $checksum, 0, $chunkData, 'id123');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
	}

	function testPushBundleChunk_ChunkSent_ReceivedCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$chunkData = 'someChunkData';
		$checksum = md5($chunkData);
		$this->api->finishPushBundle('sampleHgRepo', $hash); // clear out api
		$response = $this->api->pushBundleChunk('sampleHgRepo', $hash, 100, $checksum, 0, $chunkData, 'id123');
		$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
	}

	function testPushBundleChunk_AllChunksSent_SuccessCode() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$this->api->finishPushBundle('id123');

		$bundleData = file_get_contents(TestPath . "/data/sample.bundle");
		$bundleSize = mb_strlen($bundleData, "8bit");
		$chunkSize = 50;
		for ($offset = 0; $offset < $bundleSize; $offset+=$chunkSize) {

			$chunkData = mb_substr($bundleData, $offset, $chunkSize, "8bit");
			$actualChunkSize = mb_strlen($chunkData, "8bit");
			$response = $this->api->pushBundleChunk('sampleHgRepo', $hash, $bundleSize, md5($chunkData), $offset, $chunkData, 'id123');
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
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$response = $this->api->pushBundleChunk('sampleHgRepo', $hash, 15, md5('12345'), 0, '12345', 'id123');
		$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
		$response = $this->api->pushBundleChunk('sampleHgRepo', $hash, 15, md5('1234'), 5, '1234', 'id123');
		$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
		$response = $this->api->pushBundleChunk('sampleHgRepo', $hash, 15, md5('1234'), 9, '1234', 'id123');
		$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
		$response = $this->api->pushBundleChunk('sampleHgRepo', $hash, 15, md5('12'), 13, '12', 'id123');
		$this->assertEqual(HgResumeResponse::RESET, $response->Code);
	}

	// SOW = start of window; AKA offset
	function testPushBundleChunk_OffsetNotEqualToSOW_FailCodeReturnsSOW() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$transId = 'id123';
		$this->api->finishPushBundle($transId);
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$this->api->pushBundleChunk('sampleHgRepo', $hash, 15, md5('12345'), 0, '12345', $transId);
		$response = $this->api->pushBundleChunk('sampleHgRepo', $hash, 15, md5('12345'), 10, '12345', $transId);
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
		$this->assertEqual(5, $response->Values['offset']);
	}

	function testPushBundleChunk_PushWithOffsetZeroButSOWGreaterThanZero_ReceivedCodeReturnsSOW() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$transId = 'id123';
		$this->api->finishPushBundle($transId);
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$this->api->pushBundleChunk('sampleHgRepo', $hash, 15, md5('12345'), 0, '12345', $transId);
		$response = $this->api->pushBundleChunk('sampleHgRepo', $hash, 15, md5('12'), 0, '12', $transId);
		$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
		$this->assertEqual(5, $response->Values['offset']);
	}

	function testPushBundleChunk_PushOneChunkThenRepoChanges_PushContinuesSuccessfully() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$transId = 'id123';
		$this->api->finishPushBundle($transId);
		$filename = "fileToAdd.txt";
		$filePath = $this->testEnvironment->Path . "/" . $filename;
		file_put_contents($filePath, "sample data to add");
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));

		$bundleData = file_get_contents(TestPath . "/data/sample.bundle");
		$bundleSize = mb_strlen($bundleData, "8bit");
		$chunkSize = 50;
		for ($offset = 0; $offset < $bundleSize; $offset+=$chunkSize) {
			if ($offset == 50) {
				$hg->addAndCheckInFile($filename);
			}

			$chunkData = mb_substr($bundleData, $offset, $chunkSize, "8bit");
			$actualChunkSize = mb_strlen($chunkData, "8bit");
			$response = $this->api->pushBundleChunk('sampleHgRepo', $hash, $bundleSize, md5($chunkData), $offset, $chunkData, 'id123');
			if ($actualChunkSize < $chunkSize) { // this is the end
				$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);
			} else { // we're not finished yet
				$this->assertEqual(HgResumeResponse::RECEIVED, $response->Code);
			}
		}
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
		$this->assertEqual(md5($expectedChunkData), $response->Values['checksum']);
		$this->assertEqual(md5($response->Content), $response->Values['checksum']);
		$this->assertEqual($offset, $response->Values['offset']);
		$this->assertEqual($chunkSize, $response->Values['chunkSize']);
		$this->assertEqual(mb_strlen($wholeBundle, "8bit"), $response->Values['bundleSize']);
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
		$this->assertEqual(md5($expectedChunkData), $response->Values['checksum']);
		$this->assertEqual(md5($response->Content), $response->Values['checksum']);
		$this->assertEqual($chunkSize, $response->Values['chunkSize']);
		$this->assertEqual($transId, $response->Values['transId']);
		$this->assertEqual(mb_strlen($wholeBundle, "8bit"), $response->Values['bundleSize']);
	}

	function testPullBundleChunk_OffsetGreaterThanSize_FailCode() {
		$offset = 10000; // the sample data is only 455 bytes
		$chunkSize = 100;
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo2.zip");
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$response = $this->api->pullBundleChunk('sampleHgRepo2', $hash, $offset, $chunkSize, 'id123');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
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
			$checksum = $response->Values['checksum'];
			$chunkData = $response->Content;

			$this->assertEqual($checksum, md5($chunkData));
			$assembledBundle .= $chunkData;
			$offset += $chunkSize;
		}
		$wholeBundle = file_get_contents(TestPath . "/data/sample.bundle");
		$this->assertEqual($wholeBundle, $assembledBundle);
	}

	function testPullBundleChunk_PullOneChunkThenRepoChanges_ResetCode() {
		$offset = 0;
		$chunkSize = 50;
		$transId = 'id123';
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo2.zip");
		$hg = new HgRunner($this->testEnvironment->Path);
		$hash = trim(file_get_contents(TestPath . "/data/sample.bundle.hash"));
		$this->api->finishPullBundle($transId); // reset things on server
		$filename = "fileToAdd.txt";
		$filePath = $this->testEnvironment->Path . "/" . $filename;
		file_put_contents($filePath, "sample data to add");

		$response = $this->api->pullBundleChunk('sampleHgRepo2', $hash, 0, $chunkSize, $transId);
		$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);
		$hg->addAndCheckInFile($filename);
		$response = $this->api->pullBundleChunk('sampleHgRepo2', $hash, 50, $chunkSize, $transId);
		$this->assertEqual(HgResumeResponse::RESET, $response->Code);
	}
}

?>