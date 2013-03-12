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

	function makeBundle($baseHash, $filename, $finishFilename) {
		if ($filename == $finishFilename) {
			throw new Exception("HgRunner->makeBundle : bundle file and finish file cannot be equal");
		}
		chdir($this->repoPath);
		if ($baseHash == "0") {
			$cmd = "hg bundle --all $filename";
		} else {
			$cmd = "hg bundle --base $baseHash $filename";
		}
		$cmd = escapeshellcmd($cmd);
		// The following command will redirect all output (include output of the time command) to $finishFilename
		// Additionally, the trailing ampersand makes the hg bundle command run in the background

		// we touch the $finishFilename before execution to indicate that the bundle making process has started
		$cmd = "touch $finishFilename; /usr/bin/time --append --output=$finishFilename --format=\"makebundleprocess: %E\" $cmd > $finishFilename 2>&1 &";
		exec($cmd, $output, $returnval);
	}


	// helper function, mostly for tests
	function makeBundleAndWaitUntilFinished($baseHash, $filename, $finishFilename) {
		$this->makeBundle($baseHash, $filename, $finishFilename);
		for ($i = 0; $i < 600; $i++) {
			if (BundleHelper::isBundleFinished($finishFilename)) {
				return;
			}
			usleep(100000); // .1 seconds
		}
		throw new Exception("makeBundleAndWaitUntilFinished failed: waited 60 seconds and still no bundle!");
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
		$cmd = 'hg log --template "{node|short}\n"';
		exec($cmd, $output, $returnval);
		if (count($output) == 0) {
			exec('hg tip --template "{rev}\n"', $output2, $returnval);
			if (count($output2) == 1 and $output2[0] == "-1") {
				return array("0");
			}
			throw new Exception("command '$cmd' failed!\n");
		}
		return array_slice($output, $offset, $quantity);
	}

	function isValidBase($hash) {
		if ($hash == "0") { // special case indicating revision 0
			return true;
		}
		$foundHash = false;
		$q = 200;
		$i = 0;
		while(!$foundHash) {
			$revisions = $this->getRevisions($i, $q);
			if (count($revisions) == 0) {
				return false;
			}
			if (in_array($hash, $revisions)) {
				$foundHash = true;
			}
			$i += $q;
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