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

	function unbundle($filepath, $asyncRunner) {
		if (!is_file($filepath)) {
			throw new Exception("bundle file '$filepath' is not a file!");
		}
		chdir($this->repoPath); // NOTE: I tried with -R and it didn't work for me. CP 2012-06
		// TODO Make this async
		$cmd = "hg unbundle $filepath";
		$this->logEvent("cmd: $cmd");
		$asyncRunner->run($cmd);
	}

	function update($revision = "") {
		chdir($this->repoPath);
		$cmd = "hg update $revision";
		exec(escapeshellcmd($cmd), $output, $returnval);
	}

	/**
	 * @param string $baseHashes[]
	 * @param string $bundleFilePath
	 * @param AsyncRunner $asyncRunner
	 */
	function makeBundle($baseHashes, $bundleFilePath, $asyncRunner) {
		chdir($this->repoPath); // NOTE: I tried with -R and it didn't work for me. CP 2012-06
		if (count($baseHashes) == 1 && $baseHashes[0] == "0") {
			$cmd = "hg bundle --all $bundleFilePath";
		} else {
			$cmd = "hg bundle ";
			$baseHashes = (array)$baseHashes; //I can't figure out why this is sometimes not an array
			foreach($baseHashes as $hash)
			{
				$cmd .= "--base $hash ";
			}
			$cmd .= $bundleFilePath;
		}
		$asyncRunner->run($cmd);
	}

	/**
	 * helper function, mostly for tests
	 * @param string $baseHashes[]
	 * @param string $bundleFilePath
	 * @param AsyncRunner $asyncRunner
	 */
	function makeBundleAndWaitUntilFinished($baseHashes, $bundleFilePath, $asyncRunner) {
		$this->makeBundle($baseHashes, $bundleFilePath, $asyncRunner);
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

	function getBranchTips() {
		chdir($this->repoPath);
		exec('hg branches', $output, $returnval);
		$revisionArray = array();
		foreach($output as $branch) {
			if($branch == '') {
				$branchName = 'default';
			} else {
				$branchName = substr($branch, 0, strpos($branch, ' '));
			}
			//append the first revision for the branch to the array
			$revisionArray = array_merge($revisionArray, $this->getRevisionsInternal(0, 1, $branchName));
		}
		$revisions = array();
		foreach($revisionArray as $hashandbranch) {
			$revisions[] = substr($hashandbranch, 0, strpos($hashandbranch, ":"));
		}
		return $revisions;
	}

	//this method will return an array containing revision hash branch pairs e.g. 'fb7a8f23394d:default'
	function getRevisions($offset, $quantity) {
		return $this->getRevisionsInternal($offset, $quantity, NULL);
	}

	//this method will return an array containing revision hash branch pairs e.g. 'fb7a8f23394d:default'
	function getRevisionsInternal($offset, $quantity, $branch) {
		if ($quantity < 1) {
			throw new Exception("quantity parameter much be larger than 0");
		}
		chdir($this->repoPath);
		// I believe ':' is illegal in branch names, it is in tag, so we will use that to split the hash and branch
		$cmd = 'hg log';
		if(!is_null($branch)) {
			$cmd .= ' -b ' . $branch;
		}
		$cmd .= ' --template "{node|short}:{branch}\n"';
		exec($cmd, $output, $returnval);
		if (count($output) == 0) {
			exec('hg tip --template "{rev}:{branch}\n"', $output2, $returnval);
			if (count($output2) == 1 and startsWith($output2[0], "-1")) {
				//in the case of a tip result like '-1:default' we will return '0:default' to signal the empty repo
				$output2[0] =  str_replace("-1", "0", $output2[0]);
				return $output2; //
			}
			throw new Exception("command '$cmd' failed!\n");
		}
		return array_slice($output, $offset, $quantity);
	}

	function isValidBase($hashes) {
		if (count($hashes) == 1 && $hashes[0] == "0") { // special case indicating revision 0
			return true;
		}
		$foundHash = 0;
		$q = 200;
		$i = 0;
		while($foundHash < count($hashes)) {
			$revisions = $this->getRevisions($i, $q);
			if (count($revisions) == 0) {
				return false;
			}
			foreach($revisions as $hashandbranch) {
				$rev = substr($hashandbranch, 0, strpos($hashandbranch, ":"));
				if (array_search($rev, (array)$hashes, false) !== false) {
					$foundHash++;
					if($foundHash >= count($hashes)) {
						break;
					}
				}
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
