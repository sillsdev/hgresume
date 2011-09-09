<?php

class HgRunner {
	var $repoPath;
	const DEFAULT_HG = "/var/vcs/public";

	function __construct($repoPath = DEFAULT_HG) {
		if (is_dir($repoPath)) {
			$this->repoPath = $repoPath;
		} else {
			throw new Exception("repo '$repoPath' doesn't exist!");
		}
	}

	function unbundle($filepath) {
		if (is_file($filepath)) {
			chdir($this->repoPath);
			$cmd = "hg unbundle -u $filepath";
			exec(escapeshellcmd($cmd), $output, $returnval);
			if ($returnval != 0) {
				throw new Exception("command '$cmd' failed!");
			}
		} else {
			throw new Exception("bundle file '$filepath' is not a file!");
		}
	}

	function makeBundle($baseHash, $filename) {
		chdir($this->repoPath);
		$cmd = "hg bundle --base $baseHash $filename";
		exec(escapeshellcmd($cmd), $output, $returnval);
		if ($returnval != 0) {
			throw new Exception("command '$cmd' failed!\n");
		}
		//if (!is_file($filename)) {
		//	throw new Exception("Failed to make bundle '$filename'");
		//}
	}
}

?>