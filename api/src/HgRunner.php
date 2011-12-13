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
			exec(escapeshellcmd($cmd) . " 2> /dev/null", $output, $returnval);
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
		exec(escapeshellcmd($cmd) . " 2> /dev/null", $output, $returnval);
		if ($returnval == 1) {
			// no changesets available for that $baseHash
			// make a 0 byte bundle file
			exec(escapeshellcmd("touch $filename"));

		} elseif ($returnval != 0) {
			throw new Exception("retval is $returnval; command '$cmd' failed!\n");
		}
	}

	function getTip() {
		chdir($this->repoPath);
		$cmd = 'hg tip --template \'{node}\'';
		exec($cmd, $output, $returnval);
		if ($returnval != 0) {
			throw new Exception("command '$cmd' failed!\n");
		}
		return $output[0];
	}

	function getRevisions($offset, $quantity) {
		if ($quantity < 1) {
			throw new Exception("quantity parameter much be larger than 0");
		}
		chdir($this->repoPath);
		$template = '{node}\n';
		$cmd = "hg log -b default --template $template";
		exec(escapeshellcmd($cmd), $output, $returnval);
		if ($returnval != 0) {
			throw new Exception("command '$cmd' failed!\n");
		}
		return array_slice($output, $offset, $quantity);
	}

	function isValidBase($hash) {
		chdir($this->repoPath);
		$cmd = "hg update -r $hash";
		exec(escapeshellcmd($cmd) . " 2> /dev/null", $output, $returnval);
		if ($returnval != 0) {
			return false;
		}
		return true;
	}

	function addAndCheckInFile($filePath) {
		chdir($this->repoPath);
		$cmd = "hg add $filePath";
		exec(escapeshellcmd($cmd) . " 2> /dev/null", $output, $returnval);
		if ($returnval != 0) {
			throw new Exception("command '$cmd' failed!\n");
		}
		$cmd = "hg commit -u 'system' -m 'added file $filePath'";
		exec(escapeshellcmd($cmd) . " 2> /dev/null", $output, $returnval);
		if ($returnval != 0) {
			throw new Exception("command '$cmd' failed!\n");
		}
	}
}

?>