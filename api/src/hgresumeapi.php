<?php

class HgResumeAPI {

	function pushBundleChunk($repoId, $username, $password, $baseHash, $bundleSize, $chunkChecksum, $chunkOffset, $chunkData) {

		// how is user authentication handled?  Maybe we don't need this because we'll be using Apache HTTP auth?
		if (!$this->isValidUser($repoId, $username, $password)) {
			return new HgResumeResponse(HgResumeResponse::UNAUTHORIZED);
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
			$hg = new HgRunner($repoId);
			if ($hg->unbundle($pathToBundle)) {
				return HgResumeResponse(HgResumeResponse::SUCCESS);
			} else {
				return HgResumeResponse(HgResumeResponse::RESET); // is there really a difference between RESET and FAIL ?
			}
		} else {
			return new HgResumeResponse(HgResumeResponse::RECEIVED);
		}
	}

	function isValidUser($id, $user, $pass) {
		// how do we implement this?  Maybe we don't need to if apache takes care of the auth?
		return true;
	}

	function pullBundleChunk($repoId, $username, $password, $baseHash, $chunkOffset, $chunkSize) {
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
			$hg = new HgRunner($repoId);
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

	function getTip($repoId, $username, $password) {
		// are username and password required here?

		// query hg for the basehash; this is used for doing pullBundleChunk operation
	}

	function finishPushBundle($repoId, $username, $password, $baseHash) {
		// how is user authentication handled?  Maybe we don't need this because we'll be using Apache HTTP auth?
		if (!$this->isValidUser($repoId, $username, $password)) {
			return new HgResumeResponse(HgResumeResponse::UNAUTHORIZED);
		}

		$bundle = new HgBundleHandler($repoId, $baseHash);
		if ($bundle->cleanUpPush()) {
			return new HgResumeResponse(HgResumeResponse::SUCCESS);
		} else {
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
	}

	function finishPullBundle($repoId, $username, $password, $baseHash) {
		// how is user authentication handled?  Maybe we don't need this because we'll be using Apache HTTP auth?
		if (!$this->isValidUser($repoId, $username, $password)) {
			return new HgResumeResponse(HgResumeResponse::UNAUTHORIZED);
		}

		$bundle = new HgBundleHandler($repoId, $baseHash);
		if ($bundle->cleanUpPull()) {
			return new HgResumeResponse(HgResumeResponse::SUCCESS);
		} else {
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
	}
}

class HgResumeResponse {
	const SUCCESS = 0;
	const RECEIVED = 1;
	const RESEND = 2;
	const RESET = 3;
	const UNAUTHORIZED = 4;
	const FAIL = 5;

	var $code;
	var $returnValues;
	var $content;

	function __construct($code, $values = array(), $content = "") {
		$this->code = $code;
		$this->returnValues = $values;
		$this->content = $content;
	}
}

class BundleHelper {
	var $_repoId;
	var $_hgBaseHash;
	var $_basePath;

	function __construct($id, $hash) {
		if(!$this->_validateAlphaNumeric($id)) {
			throw new Exception("ValidationException: repoId $id did not validate as alpha numeric!");
		}
		if(!$this->_validateAlphaNumeric($hash)) {
			throw new Exception("ValidationException: baseHash $hash did not validate as alpha numeric!");
		}
		//$this->_validateRepoId($id); // should we validate the repoId as well?
		//$this->_validateBaseHash($hash);
		$this->_repoId = $id;
		$this->_hgBaseHash = $hash;
		$this->_basePath = "/var/cache/hgresume";
	}

	function getAssemblyDir() {
		$path = "{$this->_basePath}/{$this->_repoId}/{$this->_hgBaseHash}-forAssembly";
		if (!is_dir($path)) {
			if (!mkdir($path, 0755, true)) {
				throw new Exception("Failed to create bundle storage dir: $path");
			}
		}
		return $path;
	}

	function getPullFilePath() {
		$filename = "{$this->_hgBaseHash}.bundle";
		$path = $this->getPullDir();
		return "$path/$filename";
	}

	function getPullDir() {
		$path = "{$this->_basePath}/{$this->_repoId}";
		if (!is_dir($path)) {
			if (!mkdir($path, 0755, true)) {
				throw new Exception("Failed to create repo dir: $path");
			}
		}
		return $path;
	}

	function cleanUpPush() {
		$path = $this->getAssemblyDir();
		chdir($path);
		// delete all files in path
		array_map('unlink', glob("*.chunk"));
		unlink('bundle');
		return rmdir($path);
	}

	function cleanUpPull() {
		$path = $this->getPullDir();
		chdir($path);
		unlink($this->getPullFilePath());
		return !is_file($this->getPullFilePath());
	}

	function assemble() {
		$bundleDir = $this->getAssemblyDir();
		chdir($bundleDir);
		// TODO: Make this more robust to ensure chunks get glued together in the right order
		system("cat *.chunk > bundle");
		return "$bundleDir/bundle";
	}

	function _validateAlphaNumeric($str) {
		// assert that the string contains only alphanumeric digits plus underscore
		if (preg_match('/^[a-zA-Z0-9_\-]+$/', $str) > 0) {
			return true;
		} else {
			return false;
		}
	}

	function _validateBaseHash($str) {
		// check if this base hash actually exists in the hg repo????
	}
}

class HgRunner {
	var $_repoId;
	var $_hgPath;
	const DEFAULT_HG = "/var/hg";

	function __construct($id, $hgPath = DEFAULT_HG) {
		$this->_repoId = $id;
		$this->_hgPath = $hgPath;
		$this->repoPath = "{$this->_hgPath}/{$this->_repoId}";
	}

	function unbundle($filepath) {
		if (is_file($filepath)) {
			chdir($this->repoPath);
			$cmd = "hg unbundle $filepath";
			system($cmd, $returnval);
			if ($returnval != 0) {
				throw new Exception("command '$cmd' failed!");
			}
		} else {
			throw new Exception("bundle $filepath does not exist or is not a file!");
		}
	}

	function makeBundle($baseHash, $filename) {
		chdir($this->repoPath);
		$cmd = "hg bundle --base $baseHash $filename";
		system($cmd, $returnval);
		if ($returnval != 0) {
			throw new Exception("command '$cmd' failed!");
		}
	}
}

function Main() {
	//$api = new HgResumeAPI();
	//RestRpcServer::handle($api);
}

?>