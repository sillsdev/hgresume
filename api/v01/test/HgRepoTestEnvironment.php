<?php

function recursiveDelete($str){
	if(is_file($str)){
		//print "deleting $str\n";
		return @unlink($str);
	}
	elseif (substr($str, -1, 1) == '.') {
		return;
	}
	elseif(is_dir($str)){
		$str = rtrim($str, '/');
		$pattern1 = $str . '/*';
		$pattern2 = $str . '/.*';
		$scan = glob("{" . "$pattern1,$pattern2" ."}", GLOB_BRACE);
		//print count($scan) . " items found to delete for $str:\n";
		//print_r($scan);
		foreach($scan as $index=>$path){
			recursiveDelete($path);
		}
		//print "deleting $str\n";
		return @rmdir($str);
	}
}

class HgRepoTestEnvironment {
	var $Path;
	var $BasePath;
	var $RepoId;

	function __construct() {
		$this->BasePath = sys_get_temp_dir() . "/hgresume_repoTestEnvironment";
		recursiveDelete($this->BasePath);
		mkdir($this->BasePath);
	}

	function dispose() {
		recursiveDelete($this->BasePath);
	}

	function makeRepo($zipfile) {
		$zip = new ZipArchive();
		if ($zip->open($zipfile) === true) {
			$this->RepoId = pathinfo($zipfile, PATHINFO_FILENAME);
			$this->Path = $this->BasePath . "/" . $this->RepoId;
			$zip->extractTo($this->Path);
			$zip->close();
		} else {
			throw new Exception("Cannot open zipfile '$zipfile' to extract");
		}
	}
}

?>