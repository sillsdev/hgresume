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
	var $Path;
	var $BasePath;
	var $RepoId;

	function __construct() {
		$this->BasePath = sys_get_temp_dir() . "/hgresume_repoTestEnvironment";
		recursiveDelete($this->BasePath);
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