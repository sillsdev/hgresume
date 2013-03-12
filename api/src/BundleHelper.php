<?php

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

	private function getBundleDir() {
		$path = "{$this->basePath}";
		if (!is_dir($path)) {
			if (!mkdir($path, 0755, true)) {
				throw new Exception("Failed to create repo dir: $path");
			}
		}
		return $path;
	}

	function cleanUpFiles() {
		if (file_exists($this->getBundleFileName())) {
			unlink($this->getBundleFileName());
		}
		if (file_exists($this->getMetaDataFileName())) {
			unlink($this->getMetaDataFileName());
		}
		if (file_exists($this->getBundleTimeFileName())) {
			unlink($this->getBundleTimeFileName());
		}
		return true;
	}






	function isBundleCreated() {
		if (file_exists($this->getBundleFileName())) {
			return BundleHelper::isBundleFinished($this->getBundleTimeFileName());
		}
		return false;
	}

	function isBundleValid() {
		if (file_exists($this->getBundleFileName())) {
			return BundleHelper::isBundleFinishedAndValid($this->getBundleTimeFileName());
		}
	}

	// static helper functions
	static function isBundleFinishedAndValid($bundleTimeFile) {
		return BundleHelper::isBundleFinished($bundleTimeFile) and ! BundleHelper::bundleFileHasErrors($bundleTimeFile);
	}

	static function isBundleFinished($bundleTimeFile) {
		$data = file_get_contents($bundleTimeFile);
		if (strpos($data, "makebundleprocess") !== false) {
			return true;
		}
		return false;
	}

	static function bundleFileHasErrors($bundleTimeFile) {
		$data = file_get_contents($bundleTimeFile);
		if (strpos($data, "abort") !== false or
			strpos($data, "invalid") !== false or
			//strpos($data, "invalid") !== false or
			strpos($data, "exited with non-zero status 255") !== false) {
			return true;
		}
		return false;
	}








	function getBundleTimeFileName() {
		return $this->getBundleFileName() . ".isFinished";
	}

	function getBundleFileName() {
		$filename = "{$this->transId}.bundle";
		$path = $this->getBundleDir();
		return "$path/$filename";
	}

	private function getMetaDataFileName() {
		$filename = "{$this->transId}.metadata";
		$path = $this->getBundleDir();
		return "$path/$filename";
	}

	static function validateAlphaNumeric($str) {
		// assert that the string contains only alphanumeric digits plus underscore
		if (preg_match('/^[a-zA-Z0-9_\-]+$/', $str) > 0) {
			return true;
		} else {
			return false;
		}
	}

	// get the offset of the data we've collected
	function getOffset() {
		$metadata = $this->getMetadata();
		if (array_key_exists('offset', $metadata)) {
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
		$filename = $this->getMetaDataFileName();
		if (file_exists($filename)) {
			return unserialize(file_get_contents($filename));
		} else {
			return array();
		}
	}

	private function setMetadata($arr) {
		file_put_contents($this->getMetaDataFileName(), serialize($arr));
	}

	function getProp($key) {
		$metadata = $this->getMetadata();
		if (array_key_exists($key, $metadata)) {
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