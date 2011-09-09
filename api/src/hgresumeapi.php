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

		if (!is_dir($this->RepoBasePath . "/$repoId")) {
			return HgResumeResponse(HgResumeResponse::UNKNOWNID);
		}
		if ($chunkChecksum != md5($chunkData)) {
			// invalid checksum: resend chunk data
			return HgResumeResponse(HgResumeResponse::RESEND);
		}

		// the repoId and baseHash form a unique id for this bundle upload.
		// if the chunkOffset is 0, then we need to create a new file
		// if the chunkOffset > 0, then we need to append to the existing bundle file

		$bundle = new BundleHelper($repoId, $baseHash);
		$chunkPath = $bundle->getAssemblyDir();
		$chunkFilename = "$chunkPath/$chunkOffset.chunk";

		if ($chunkOffset < $bundleSize) {
			// write chunk to file
			file_put_contents($chunkFilename, $chunkData);
		}

		// final chunk
		// create the bundle and update the hg repo with the bundle
		if ($bundleSize == $chunkOffset + filesize($chunkFilename)) {
			$pathToBundle = $bundle->assemble();
			$hg = new HgRunner($this->RepoBasePath . "/$repoId");
			if ($hg->unbundle($pathToBundle)) {
				return HgResumeResponse(HgResumeResponse::SUCCESS);
			} else {
				return HgResumeResponse(HgResumeResponse::RESET); // is there really a difference between RESET and FAIL ?
			}
		} else {
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

		$bundle = new BundleHelper($repoId, $baseHash);
		$pullDir = $bundle->getPullDir();
		$filename = $bundle->getPullFilePath();
		// make the bundle if it doesn't already exist
		if (!is_file($filename)) {
			$hg = new HgRunner($this->RepoBasePath . "/$repoId");
			$hg->makeBundle($baseHash, $filename);
		}

		$bundleSize = filesize($filename);

		// read the specified chunk of the bundle file
		$fileHandle = fopen($bundle, "r");
		fseek($fileHandle, $chunkOffset);
		$chunkData = fread($fileHandle, $chunkSize);
		fclose($fileHandle);
		$actualChunkSize = mb_strlen($chunkData, "8bit");
		$checksum = md5($chunkData);
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

		$bundle = new HgBundleHandler($repoId, $baseHash);
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

		$bundle = new HgBundleHandler($repoId, $baseHash);
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