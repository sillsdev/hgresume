<?php

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
		if (file_exists('bundle')) {
			unlink('bundle');
		}
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

?>