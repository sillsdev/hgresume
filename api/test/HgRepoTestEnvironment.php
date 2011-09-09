<?php

function recursiveDelete($str){
	if(is_file($str)){
		return @unlink($str);
	}
	elseif(is_dir($str)){
		$scan = glob(rtrim($str,'/').'/*');
		foreach($scan as $index=>$path){
			recursiveDelete($path);
		}
		return @rmdir($str);
	}
}

class HgRepoTestEnvironment {
	var $_path;

	function __construct() {
		$this->_path = sys_get_temp_dir() . "/testHgRepo";
		recursiveDelete($this->_path);
	}

	function getPath() {
		return $this->_path;
	}

	function dispose() {
		recursiveDelete($this->_path);
	}

	function makeRepo($zipfile = "data/sampleHgRepo.zip") {
		$zip = new ZipArchive();
		if ($zip->open($zipfile) === true) {
			$zip->extractTo($this->_path);
			$zip->close();
		} else {
			throw new Exception("Cannot open zipfile '$zipfile' to extract");
		}
	}

	function getBundlePath() {
		return "data/sample.bundle";
	}
}

?>