<?php

require_once('AsyncRunner.php');

class HgRunner {
	var $repoPath;
	var $logState;
	const DEFAULT_HG = "/var/vcs/public";

	function __construct($repoPath = DEFAULT_HG) {
		if (is_dir($repoPath)) {
			$this->repoPath = $repoPath;
		} else {
			throw new ValidationException("repo '$repoPath' doesn't exist!");
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

	function unbundle($filepath, $asyncRunner) {
		if (!is_file($filepath)) {
			throw new HgException("bundle file '$filepath' is not a file!");
		}
		chdir($this->repoPath); // NOTE: I tried with -R and it didn't work for me. CP 2012-06

		// run hg incoming to make sure this bundle is related to the repo
		$cmd = "hg incoming $filepath";
		$this->logEvent("cmd: $cmd");
		$asyncRunner->run($cmd);
		$asyncRunner->synchronize();
		$output = $asyncRunner->getOutput();
		if (preg_match('/abort:.*unknown parent/', $output)) {
			throw new UnrelatedRepoException("Project is unrelated!  (unrelated bundle pushed to repo)");
		}
		if (preg_match('/parent:\s*-1:/', $output)) {
			throw new UnrelatedRepoException("Project is unrelated!  (unrelated bundle pushed to repo)");
		}
		if (preg_match('/abort:.*not a Mercurial bundle/', $output)) {
			throw new Exception("Project cannot be updated!  (corrupt bundle pushed to repo)");
		}

		$cmd = "hg unbundle $filepath";
		$this->logEvent("cmd: $cmd");
		$asyncRunner->run($cmd);
	}

	function update($asyncRunner, $revision = "") {
		chdir($this->repoPath);
		$cmd = "hg update $revision";
		$this->logEvent("cmd: $cmd");
		$asyncRunner->run($cmd);
	}

	/**
	 * @param string $baseHash
	 * @param string $bundleFilePath
	 * @param AsyncRunner $asyncRunner
	 */
	function makeBundle($baseHash, $bundleFilePath, $asyncRunner) {
		chdir($this->repoPath); // NOTE: I tried with -R and it didn't work for me. CP 2012-06
		if ($baseHash == "0") {
			$cmd = "hg bundle --all $bundleFilePath";
		} else {
			$cmd = "hg bundle --base $baseHash $bundleFilePath";
		}
		$asyncRunner->run($cmd);
	}

	/**
	 * helper function, mostly for tests
	 * @param string $baseHash
	 * @param string $bundleFilePath
	 * @param AsyncRunner $asyncRunner
	 */
	function makeBundleAndWaitUntilFinished($baseHash, $bundleFilePath, $asyncRunner) {
		$this->makeBundle($baseHash, $bundleFilePath, $asyncRunner);
		while (true) {
			if ($asyncRunner->isComplete()) {
				break;
			}
			usleep(1000);
		}
	}

	function getTip() {
		$revisionArray = $this->getRevisions(0, 1);
		return $revisionArray[0];
	}

	function getRevisions($offset, $quantity) {
		if ($quantity < 1) {
			throw new ValidationException("quantity parameter much be larger than 0");
		}
		chdir($this->repoPath);
		$cmd = 'hg log --template "{node|short}\n"';
		exec($cmd, $output, $returnval);
		if (count($output) == 0) {
			exec('hg tip --template "{rev}\n"', $output2, $returnval);
			if (count($output2) == 1 and $output2[0] == "-1") {
				return array("0");
			}
			throw new HgException("command '$cmd' failed!\n");
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

	// helper function used in tests
	function addAndCheckInFile($filePath) {
		chdir($this->repoPath);
		$cmd = "hg add $filePath";
		exec(escapeshellcmd($cmd) . " 2> /dev/null", $output, $returnval);
		if ($returnval != 0) {
			throw new HgException("command '$cmd' failed!\n");
		}
		$cmd = "hg commit -u 'system' -m 'added file $filePath'";
		exec(escapeshellcmd($cmd) . " 2> /dev/null", $output, $returnval);
		if ($returnval != 0) {
			throw new HgException("command '$cmd' failed!\n");
		}
	}
}

?>