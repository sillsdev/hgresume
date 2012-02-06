<?php

class HgRunner {
	var $repoPath;
	var $logState;
	const DEFAULT_HG = "/var/vcs/public";

	function __construct($repoPath = DEFAULT_HG) {
		if (is_dir($repoPath)) {
			$this->repoPath = $repoPath;
		} else {
			throw new Exception("repo '$repoPath' doesn't exist!");
		}
		$logState = false;
	}

	private function logEvent($message) {
		$logFilename = $this->repoPath . "/hgRunner.log";
		$timestamp = date("YMd \TH.i");
		if (!$this->logState) {
			$this->logState = true;
			file_put_contents($logFilename, "\n$timestamp\n", FILE_APPEND | LOCK_EX);
		}
		file_put_contents($logFilename, "$message\n", FILE_APPEND | LOCK_EX);
	}

	function unbundle($filepath) {
		if (is_file($filepath)) {
			chdir($this->repoPath);
			$cmd = "hg unbundle $filepath";
			$this->logEvent("cmd: $cmd");
			exec(escapeshellcmd($cmd), $output, $returnval);
			if ($returnval != 0) {
				$this->logEvent("previous cmd failed with returnval '$returnval' and output "
				. implode("|", $output));
				throw new Exception("command '$cmd' failed!");
			}
		} else {
			throw new Exception("bundle file '$filepath' is not a file!");
		}
	}

	function update($revision = "") {
		chdir($this->repoPath);
		$cmd = "hg update $revision";
		exec(escapeshellcmd($cmd), $output, $returnval);
	}

	function makeBundle($baseHash, $filename) {
		chdir($this->repoPath);
		if ($baseHash == "0") {
			$cmd = "hg bundle --all $filename";
		} else {
			$cmd = "hg bundle --base $baseHash $filename";
		}
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
		$revisionArray = $this->getRevisions(0, 1);
		return $revisionArray[0];
	}

	function getRevisions($offset, $quantity) {
		if ($quantity < 1) {
			throw new Exception("quantity parameter much be larger than 0");
		}
		chdir($this->repoPath);
		$template = '{node|short}\n';
		$cmd = "hg log -b default --template $template";
		exec(escapeshellcmd($cmd), $output, $returnval);
		if ($returnval != 0) {
			throw new Exception("command '$cmd' failed!\n");
		}
		return array_slice($output, $offset, $quantity);
	}

	function isValidBase($hash) {
		if ($hash == "0") { // special case indicating revision 0
			return true;
		}
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