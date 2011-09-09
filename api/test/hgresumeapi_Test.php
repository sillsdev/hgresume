<?php
require_once(dirname(__FILE__) . '/testconfig.php');
require_once(SimpleTestPath . '/autorun.php');
require_once(SourcePath . "/HgResumeAPI.php");
require_once(SourcePath . "/HgResumeResponse.php");

class TestOfHgResumeAPI extends UnitTestCase {

	var $testEnvironment;

	function setUp() {
		$this->testEnvironment = new HgRepoTestEnvironment();
	}

	function tearDown() {
		$this->testEnvironment->dispose();
	}

	function testGetTip_IdExists_ReturnsSuccessCodeAndHash() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$repoId = $this->testEnvironment->RepoId;
		$api = new HgResumeAPI($this->testEnvironment->BasePath);
		$response = $api->getTip($repoId);
		$this->assertEqual(HgResumeResponse::SUCCESS, $response->Code);

		$this->assertEqual($response->Values['Tip'], trim(file_get_contents(TestPath . "/data/sample.bundle.hash")));
	}

	function testGetTip_IdExists_ReturnsHash() {
		$this->testEnvironment->makeRepo(TestPath . "/data/sampleHgRepo.zip");
		$api = new HgResumeAPI($this->testEnvironment->BasePath);
		$response = $api->getTip('invalidid');
		$this->assertEqual(HgResumeResponse::FAIL, $response->Code);
	}

	// we won't test finishPullBundle since it is just a wrapper for BundleHelper->cleanUpPull, and that is already tested

	// we won't test finishPushBundle since it is just a wrapper for BundleHelper->cleanUpPush, and that is already tested

	function testPushBundleChunk_BogusId_UnknownCode() {
	}

	function testPushBundleChunk_DataBadChecksum_ResendCode() {
	}

	function testPushBundleChunk_InvalidOffset_FailCode() {
	}

	function testPushBundleChunk_NoData_FailCode() {
	}

	function testPushBundleChunk_InvalidBaseHash_FailCode() {
	}

	function testPushBundleChunk_InvalidBundleSize_FailCode() {
	}

	function testPushBundleChunk_ChunkSent_ReceivedCode() {
	}

	function testPushBundleChunk_AllChunksSent_SuccessCode() {
	}

	function testPushBundleChunk_AllChunksSentButBadDataChunkSoBundleFails_ResetCode() {
	}







	function testPullBundleChunk_BogusId_UnknownCode() {
	}

	function testPullBundleChunk_InvalidHash_FailCode() {
	}

	function testPullBundleChunk_OffsetZero_ValidData() {
	}

	function testPullBundleChunk_MiddleOffset_ValidData() {
	}

	function testPullBundleChunk_OffsetGreaterThanSize_FailCode() {
	}

	function testPullBundleChunk_OffsetGreaterThanSize_FailCode() {
	}


}

?>