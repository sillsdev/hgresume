<?php

class HgRunner {
	var $repoPath;
	const DEFAULT_HG = "/var/vcs/public";

	function __construct($repoPath = DEFAULT_HG) {
		$this->repoPath = $repoPath;
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

?>