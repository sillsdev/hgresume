<?php

define('CACHE_PATH', "/var/cache/hgresume");

class BundleHelper {
	private $transId;
	private $basePath;

	function __construct($id) {
		if(!BundleHelper::validateAlphaNumeric($id)) {
			throw new Exception("ValidationException: transId $id did not validate as alpha numeric!");
		}
		$this->transId = $id;
		$this->basePath = CACHE_PATH;
	}

	function getAssemblyDir() {
		$path = "{$this->basePath}/{$this->transId}-assembly";
		if (!is_dir($path)) {
			if (!mkdir($path, 0755, true)) {
				throw new Exception("Failed to create bundle storage dir: $path");
			}
		}
		return $path;
	}

	function getPullFilePath() {
		$filename = "{$this->transId}.bundle";
		$path = $this->getPullDir();
		return "$path/$filename";
	}

	function getPullDir() {
		$path = "{$this->basePath}";
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
		if (file_exists('metadata')) {
			unlink('metadata');
		}
		return rmdir($path);
	}

	function cleanUpPull() {
		$path = $this->getPullDir();
		$bundleFile = $this->getPullFilePath();
		chdir($path);
		if (file_exists($bundleFile)) {
			unlink($bundleFile);
		}
		return !is_file($bundleFile);
	}

	function assemble() {
		$bundleDir = $this->getAssemblyDir();
		chdir($bundleDir);
		// TODO: Make this more robust to ensure chunks get glued together in the right order
		system("cat *.chunk > bundle");
		return "$bundleDir/bundle";
	}

	static function validateAlphaNumeric($str) {
		// assert that the string contains only alphanumeric digits plus underscore
		if (preg_match('/^[a-zA-Z0-9_\-]+$/', $str) > 0) {
			return true;
		} else {
			return false;
		}
	}

	// TODO store metadata in an mySQL database to keep information about
	// transaction activity

	// get the offset of the data we've collected
	function getOffset() {
		$metadata = $this->getMetadata();
		if ($metadata['offset']) {
			return $metadata['offset'];
		} else {
			return 0;
		}
	}

	function setOffset($val) {
		$metadata = $this->getMetadata();
		$metadata['offset'] = intval($val);
		$this->setMetadata($metadata);
	}

	private function getMetadata() {
		$filename = $this->getAssemblyDir() . "/metadata";
		if (file_exists($filename)) {
			return unserialize(file_get_contents($filename));
		} else {
			return array();
		}
	}

	private function setMetadata($arr) {
		$filename = $this->getAssemblyDir() . "/metadata";
		file_put_contents($filename, serialize($arr));
	}

	function getProp($key) {
		$metadata = $this->getMetadata();
		if ($metadata[$key]) {
			return $metadata[$key];
		} else {
			return "";
		}
	}

	function setProp($key, $value) {
		$metadata = $this->getMetadata();
		$metadata[$key] = $value;
		$this->setMetadata($metadata);
	}

	function hasProp($key) {
		$metadata = $this->getMetadata();
		return array_key_exists($key, $metadata);
	}
}

?>