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

		// how is user authentication handled?  Maybe we don't need this because we'll be using Apache HTTP auth?
		//if (!$this->isValidUser($repoId, $username, $password)) {
		//	return new HgResumeResponse(HgResumeResponse::UNAUTHORIZED);
		//}

		/********* Parameter validation and checking ************/
		// $repoId
		if (!is_dir($this->RepoBasePath . "/$repoId")) {
			return new HgResumeResponse(HgResumeResponse::UNKNOWNID);
		}
		$hg = new HgRunner($this->RepoBasePath . "/$repoId");
		// $chunkChecksum
		if ($chunkChecksum != md5($chunkData)) {
			// invalid checksum: resend chunk data
			return new HgResumeResponse(HgResumeResponse::RESEND);
		}
		// $chunkOffset
		if ($chunkOffset < 0 or $chunkOffset >= $bundleSize) {
			//invalid offset
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
		// $chunkData
		$dataSize = mb_strlen($chunkData, "8bit");
		if ($dataSize == 0 or ($dataSize > $bundleSize - $chunkOffset)) {
			// no data or data larger than advertised bundle size
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
		// $bundleSize
		if (intval($bundleSize) < 0) {
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
		// $baseHash
		if (!$hg->isValidBase($baseHash)) {
			return new HgResumeResponse(HgResumeResponse::FAIL);
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
				$response = new HgResumeResponse(HgResumeResponse::RESET);
				$this->finishPushBundle($repoId, $baseHash); // clean up bundle assembly cache
			}
			return $response;
		} else {
			// received the chunk, but it's not the last one; we expect more chunks
			return new HgResumeResponse(HgResumeResponse::RECEIVED);
		}
	}

	//function isValidUser($id, $user, $pass) {
		// how do we implement this?  Maybe we don't need to if apache takes care of the auth?
	//	return true;
	//}

	function pullBundleChunk($repoId, $baseHash, $chunkOffset, $chunkSize) {
		// this function returns the following values via HTTP
		// chunkSize
		// chunkChecksum
		// chunkData
		// bundleSize

		/********* Parameter validation and checking ************/
		// $repoId
		if (!is_dir($this->RepoBasePath . "/$repoId")) {
			return new HgResumeResponse(HgResumeResponse::UNKNOWNID);
		}
		$hg = new HgRunner($this->RepoBasePath . "/$repoId");
		// $chunkOffset
		if ($chunkOffset < 0) {
			//invalid offset
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
		// $baseHash
		if (!$hg->isValidBase($baseHash)) {
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}


		$bundle = new BundleHelper($repoId, $baseHash);
		$pullDir = $bundle->getPullDir();
		$filename = $bundle->getPullFilePath();
		// make the bundle if it doesn't already exist
		if (!is_file($filename)) {
			$hg = new HgRunner($this->RepoBasePath . "/$repoId");
			$hg->makeBundle($baseHash, $filename);
		}

		$bundleSize = filesize($filename);

		if ($bundleSize == 0) {
			return new HgResumeResponse(HgResumeResponse::NOCHANGE);
		}

		// FAIL if offset is greater or equal to than bundlesize
		if ($chunkOffset >= $bundleSize) {
			return new HgResumeResponse(HgResumeResponse::FAIL);
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
		// how is user authentication handled?  Maybe we don't need this because we'll be using Apache HTTP auth?
		//if (!$this->isValidUser($repoId, $username, $password)) {
		//	return new HgResumeResponse(HgResumeResponse::UNAUTHORIZED);
		//}

		$bundle = new BundleHelper($repoId, $baseHash);
		if ($bundle->cleanUpPush()) {
			return new HgResumeResponse(HgResumeResponse::SUCCESS);
		} else {
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
	}

	function finishPullBundle($repoId, $baseHash) {
		// how is user authentication handled?  Maybe we don't need this because we'll be using Apache HTTP auth?
		//if (!$this->isValidUser($repoId, $username, $password)) {
		//	return new HgResumeResponse(HgResumeResponse::UNAUTHORIZED);
		//}

		$bundle = new BundleHelper($repoId, $baseHash);
		if ($bundle->cleanUpPull()) {
			return new HgResumeResponse(HgResumeResponse::SUCCESS);
		} else {
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
	}
}


function Main() {
	//$api = new HgResumeAPI();
	//RestRpcServer::handle($api);
}

?>