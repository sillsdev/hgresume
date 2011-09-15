<?php

require_once("HgResumeResponse.php");
require_once("HgRunner.php");
require_once("BundleHelper.php");

class HgResumeAPI {
	var $RepoBasePath;

	// Note: API_VERSION is defined in HgResumeResponse.php

	function __construct($repoPath = "/var/vcs/public") {
		$this->RepoBasePath = $repoPath;
	}

	function pushBundleChunk($repoId, $baseHash, $bundleSize, $checksum, $offset, $data, $transId) {

		/********* Parameter validation and checking ************/
		// $repoId
		if (!is_dir($this->RepoBasePath . "/$repoId")) {
			return new HgResumeResponse(HgResumeResponse::UNKNOWNID);
		}
		$hg = new HgRunner($this->RepoBasePath . "/$repoId");
		// $checksum
		if ($checksum != md5($data)) {
			// invalid checksum: resend chunk data
			return new HgResumeResponse(HgResumeResponse::RESEND, array('Error' => 'checksum failed', 'transId' => $transId));
		}
		// $offset
		if ($offset < 0 or $offset >= $bundleSize) {
			//invalid offset
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid offset'));
		}
		// $data
		$dataSize = mb_strlen($data, "8bit");
		if ($dataSize == 0 or ($dataSize > $bundleSize - $offset)) {
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

		$bundle = new BundleHelper($transId);

		// if the data sent falls before the start of window, mark it as received and reply with correct startOfWindow
		// Fail if there is overlap or a mismatch between the start of window and the data offset
		$startOfWindow = $bundle->getOffset();
		if ($offset != $startOfWindow) { // these are usually equal.  It could be a client programming error if they are not
			if ($dataSize + $offset <= $startOfWindow) {
				return new HgResumeResponse(HgResumeResponse::RECEIVED, array('offset' => $startOfWindow, 'Note' => 'offset mismatch with startOfWindow'));
			} else {
				return new HgResumeResponse(HgResumeResponse::FAIL, array('offset' => $startOfWindow, 'Error' => "offset mismatch with startOfWindow"));
			}
		}

		$chunkPath = $bundle->getAssemblyDir();
		$chunkFilename = sprintf("%s/%010d.chunk", $chunkPath, $offset);

		if ($offset < $bundleSize) {
			file_put_contents($chunkFilename, $data);
		}

		// for the final chunk; assemble the bundle and apply the bundle
		if ($bundleSize == $offset + $dataSize) {
			try {
				$pathToBundle = $bundle->assemble();
				$hg->unbundle($pathToBundle);
				$bundle->setOffset($offset + $dataSize);

				$responseValues = array('transId' => $transId);
				$response = new HgResumeResponse(HgResumeResponse::SUCCESS, $responseValues);
				$this->finishPushBundle($transId); // clean up bundle assembly cache
			} catch (Exception $e) {
				// is there really a difference between RESET and FAIL?
				$bundle->setOffset(0);
				$responseValues = array('Error' => substr($e->getMessage(), 0, 1000));
				$responseValues['transId'] = $transId;
				$response = new HgResumeResponse(HgResumeResponse::RESET, $responseValues);
				$this->finishPushBundle($transId); // clean up bundle assembly cache
			}
			return $response;
		} else {
			// received the chunk, but it's not the last one; we expect more chunks
			$bundle->setOffset($offset + $dataSize);
			$responseValues = array('transId' => $transId, 'sow' => $sow);
			return new HgResumeResponse(HgResumeResponse::RECEIVED, $responseValues);
		}
	}


	function pullBundleChunk($repoId, $baseHash, $offset, $chunkSize, $transId) {

		/********* Parameter validation and checking ************/
		// $repoId
		if (!is_dir($this->RepoBasePath . "/$repoId")) {
			return new HgResumeResponse(HgResumeResponse::UNKNOWNID);
		}
		$hg = new HgRunner($this->RepoBasePath . "/$repoId");
		// $offset
		if ($offset < 0) {
			//invalid offset
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid offset'));
		}
		// $baseHash
		if (!$hg->isValidBase($baseHash)) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid baseHash'));
		}

		try {
			$bundle = new BundleHelper($transId);
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
		if ($offset >= $bundleSize) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'offset greater than bundle size'));
		}

		// read the specified chunk of the bundle file
		$fileHandle = fopen($filename, "r");
		fseek($fileHandle, $offset);
		$data = fread($fileHandle, $chunkSize);
		fclose($fileHandle);
		$actualChunkSize = mb_strlen($data, "8bit");
		$checksum = md5($data);

		// construct and return the response
		$response = new HgResumeResponse(HgResumeResponse::SUCCESS);
		$response->Values = array(
				'bundleSize' => $bundleSize,
				'chunkSize' => $actualChunkSize,
				'checksum' => $checksum,
				'transId' => $transId,
				'offset' => $offset);
		$response->Content = $data;
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

	function finishPushBundle($transId) {
		$bundle = new BundleHelper($transId);
		if ($bundle->cleanUpPush()) {
			return new HgResumeResponse(HgResumeResponse::SUCCESS);
		} else {
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
	}

	function finishPullBundle($transId) {
		$bundle = new BundleHelper($transId);
		if ($bundle->cleanUpPull()) {
			return new HgResumeResponse(HgResumeResponse::SUCCESS);
		} else {
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
	}
}

?>