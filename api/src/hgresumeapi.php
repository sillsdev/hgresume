<?php

require_once("HgResumeResponse.php");
require_once("HgRunner.php");
require_once("BundleHelper.php");

class HgResumeAPI {
	var $RepoBasePath;

	function __construct($repoPath = "/var/vcs/public") {
		$this->RepoBasePath = $repoPath;
	}

	function pushBundleChunk($repoId, $baseHash, $bundleSize, $chunkChecksum, $chunkOffset, $chunkData) {

		/********* Parameter validation and checking ************/
		// $repoId
		if (!is_dir($this->RepoBasePath . "/$repoId")) {
			return new HgResumeResponse(HgResumeResponse::UNKNOWNID);
		}
		$hg = new HgRunner($this->RepoBasePath . "/$repoId");
		// $chunkChecksum
		if ($chunkChecksum != md5($chunkData)) {
			// invalid checksum: resend chunk data
			return new HgResumeResponse(HgResumeResponse::RESEND, array('Error' => 'checksum failed'));
		}
		// $chunkOffset
		if ($chunkOffset < 0 or $chunkOffset >= $bundleSize) {
			//invalid offset
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid offset'));
		}
		// $chunkData
		$dataSize = mb_strlen($chunkData, "8bit");
		if ($dataSize == 0 or ($dataSize > $bundleSize - $chunkOffset)) {
			// no data or data larger than advertised bundle size
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'no data or data larger than remaining bundle size'));
		}
		// $bundleSize
		if (intval($bundleSize) < 0) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'negative bundle size'));
		}
		// $baseHash
		if (!$hg->isValidBase($baseHash)) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid baseHash'));
		}

		$bundle = new BundleHelper($repoId, $baseHash);
		$chunkPath = $bundle->getAssemblyDir();
		$chunkFilename = sprintf("%s/%010d.chunk", $chunkPath, $chunkOffset);
		//$chunkFilename = "$chunkPath/$chunkOffset.chunk";

		if ($chunkOffset < $bundleSize) {
			file_put_contents($chunkFilename, $chunkData);
		}

		// for the final chunk; asseble the bundle and apply the bundle
		if ($bundleSize == $chunkOffset + filesize($chunkFilename)) {
			try {
				$pathToBundle = $bundle->assemble();
				$hg->unbundle($pathToBundle);
				$response = new HgResumeResponse(HgResumeResponse::SUCCESS);
				$this->finishPushBundle($repoId, $baseHash); // clean up bundle assembly cache
			} catch (Exception $e) {
				// is there really a difference between RESET and FAIL?
				$responseValues = array('Error' => substr($e->getMessage(), 0, 1000));
				$response = new HgResumeResponse(HgResumeResponse::RESET, $responseValues);
				$this->finishPushBundle($repoId, $baseHash); // clean up bundle assembly cache
			}
			return $response;
		} else {
			// received the chunk, but it's not the last one; we expect more chunks
			return new HgResumeResponse(HgResumeResponse::RECEIVED);
		}
	}


	function pullBundleChunk($repoId, $baseHash, $chunkOffset, $chunkSize) {

		/********* Parameter validation and checking ************/
		// $repoId
		if (!is_dir($this->RepoBasePath . "/$repoId")) {
			return new HgResumeResponse(HgResumeResponse::UNKNOWNID);
		}
		$hg = new HgRunner($this->RepoBasePath . "/$repoId");
		// $chunkOffset
		if ($chunkOffset < 0) {
			//invalid offset
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid chunkOffset'));
		}
		// $baseHash
		if (!$hg->isValidBase($baseHash)) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid baseHash'));
		}

		try {
			$bundle = new BundleHelper($repoId, $baseHash);
			$pullDir = $bundle->getPullDir();
			$filename = $bundle->getPullFilePath();
			// make the bundle if it doesn't already exist
			if (!is_file($filename)) {
				$hg = new HgRunner($this->RepoBasePath . "/$repoId");
				$hg->makeBundle($baseHash, $filename);
			}
		} catch (Exception $e) {
			$response = array('Error' => substr($e->getMessage(), 0, 1000));
			return new HgResumeResponse(HgResumeResponse::FAIL, $response);
		}

		$bundleSize = filesize($filename);
		if ($bundleSize == 0) {
			return new HgResumeResponse(HgResumeResponse::NOCHANGE);
		}

		// FAIL if offset is greater or equal to than bundlesize
		if ($chunkOffset >= $bundleSize) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'offset greater than bundle size'));
		}

		// read the specified chunk of the bundle file
		$fileHandle = fopen($filename, "r");
		fseek($fileHandle, $chunkOffset);
		$chunkData = fread($fileHandle, $chunkSize);
		fclose($fileHandle);
		$actualChunkSize = mb_strlen($chunkData, "8bit");
		$checksum = md5($chunkData);

		// construct and return the response
		$response = new HgResumeResponse(HgResumeResponse::SUCCESS);
		$response->Values = array(
				'bundleSize' => $bundleSize,
				'chunkSize' => $actualChunkSize,
				'checksum' => $checksum,
				'offset' => $chunkOffset);
		$response->Content = $chunkData;
		return $response;
	}

	function getTip($repoId) {
		try {
			$hg = new HgRunner("{$this->RepoBasePath}/$repoId");
			$response = array('Tip' => $hg->getTip());
			$hgresponse = new HgResumeResponse(HgResumeResponse::SUCCESS, $response);
		} catch (Exception $e) {
			$response = array('Error' => substr($e->getMessage(), 0, 1000));
			$hgresponse = new HgResumeResponse(HgResumeResponse::FAIL, $response);
		}
		return $hgresponse;
	}

	function finishPushBundle($repoId, $baseHash) {
		$bundle = new BundleHelper($repoId, $baseHash);
		if ($bundle->cleanUpPush()) {
			return new HgResumeResponse(HgResumeResponse::SUCCESS);
		} else {
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
	}

	function finishPullBundle($repoId, $baseHash) {
		$bundle = new BundleHelper($repoId, $baseHash);
		if ($bundle->cleanUpPull()) {
			return new HgResumeResponse(HgResumeResponse::SUCCESS);
		} else {
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
	}
}

?>