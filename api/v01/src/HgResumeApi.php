<?php

require_once("HgResumeResponse.php");
require_once("HgRunner.php");
require_once("BundleHelper.php");

class HgResumeAPI {
	var $RepoBasePath;

	// Note: API_VERSION is defined in config.php

	function __construct($repoPath = "/var/vcs/public") {
		$this->RepoBasePath = $repoPath;
	}

	function pushBundleChunk($repoId, $bundleSize, $offset, $data, $transId) {
		$availability = $this->isAvailable();
		if ($availability->Code == HgResumeResponse::NOTAVAILABLE) {
			return $availability;
		}

		/********* Parameter validation and checking ************/
		// $repoId
		if (trim($repoId) == '' or !is_dir($this->RepoBasePath . "/$repoId")) {
			return new HgResumeResponse(HgResumeResponse::UNKNOWNID);
		}
		$hg = new HgRunner($this->RepoBasePath . "/$repoId");

		// $offset
		if ($offset < 0 or $offset >= $bundleSize) {
			//invalid offset
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid offset'));
		}
		// $data
		$dataSize = mb_strlen($data, "8bit");
		if ($dataSize == 0) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'no data sent'));
		}
		if ($dataSize > $bundleSize - $offset) {
			// no data or data larger than advertised bundle size
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'data sent is larger than remaining bundle size'));
		}
		// $bundleSize
		if (intval($bundleSize) < 0) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'negative bundle size'));
		}

		$bundle = new BundleHelper($transId);

		// if the data sent falls before the start of window, mark it as received and reply with correct startOfWindow
		// Fail if there is overlap or a mismatch between the start of window and the data offset
		$startOfWindow = $bundle->getOffset();
		if ($offset != $startOfWindow) { // these are usually equal.  It could be a client programming error if they are not
			if ($dataSize + $offset <= $startOfWindow) {
				return new HgResumeResponse(HgResumeResponse::RECEIVED, array('sow' => $startOfWindow, 'Note' => 'offset mismatch with startOfWindow'));
			} else {
				return new HgResumeResponse(HgResumeResponse::FAIL, array('sow' => $startOfWindow, 'Error' => "offset mismatch with startOfWindow"));
			}
		}

		// write chunk data to bundle file
		$bundleFile = fopen($bundle->getBundleFileName(), "a");
		fseek($bundleFile, $offset);
		fwrite($bundleFile, $data);
		fclose($bundleFile);

		// for the final chunk; assemble the bundle and apply the bundle
		if ($bundleSize == $offset + $dataSize) {
			try {
				$hg->unbundle($bundle->getBundleFileName());
				$bundle->setOffset($bundleSize);

				$responseValues = array('transId' => $transId);
				$response = new HgResumeResponse(HgResumeResponse::SUCCESS, $responseValues);
				$this->finishPushBundle($transId); // clean up bundle assembly cache
			} catch (Exception $e) {
				$bundle->setOffset(0);
				$responseValues = array('Error' => substr($e->getMessage(), 0, 1000));
				$responseValues['transId'] = $transId;
				$response = new HgResumeResponse(HgResumeResponse::RESET, $responseValues);
				$this->finishPushBundle($transId); // clean up bundle assembly cache
			}
			return $response;
		} else {
			// received the chunk, but it's not the last one; we expect more chunks
			$newSow = $offset + $dataSize;
			$bundle->setOffset($newSow);
			$responseValues = array('transId' => $transId, 'sow' => $newSow);
			return new HgResumeResponse(HgResumeResponse::RECEIVED, $responseValues);
		}
	}


	function pullBundleChunk($repoId, $baseHash, $offset, $chunkSize, $transId) {
		$availability = $this->isAvailable();
		if ($availability->Code == HgResumeResponse::NOTAVAILABLE) {
			return $availability;
		}

		/********* Parameter validation and checking ************/
		// $repoId
		if (trim($repoId) == '' or !is_dir($this->RepoBasePath . "/$repoId")) {
			return new HgResumeResponse(HgResumeResponse::UNKNOWNID);
		}

		$hg = new HgRunner($this->RepoBasePath . "/$repoId");
		$bundle = new BundleHelper($transId);

		// $offset
		if ($offset < 0) {
			//invalid offset
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid offset'));
		}
		// $baseHash
		if (!$hg->isValidBase($baseHash)) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid baseHash'));
		}

		// if the server's tip is equal to the baseHash requested, then no pull is necessary
		if ($hg->getTip() == $baseHash) {
			return new HgResumeResponse(HgResumeResponse::NOCHANGE);
		}

		$bundleSize = 0;
		$bundleFileWasCreated = false;
		try {
			//$pullDir = $bundle->getPullDir();
			$filename = $bundle->getBundleFileName();
			if (!is_file($filename)) {
				$bundleFileWasCreated = true;
				// this is the first pull request; make a new bundle
				$hg->makeBundle($baseHash, $filename);
				$bundle->setProp("tip", $hg->getTip());
				$bundle->setProp("repoId", $repoId);
			}
			// the bundle file may not be completely finished writing at this time
			// but currently we don't see any reason to wait until the bundle
			// has finished being created
			$bundleSize = filesize($filename);

		} catch (Exception $e) {
			$response = array('Error' => substr($e->getMessage(), 0, 1000));
			return new HgResumeResponse(HgResumeResponse::FAIL, $response);
		}

		// if the client requests an offset greater than 0 but less than the bundleSize, but the bundle needed to be created on this request,
		// send the RESET response since the server's bundle cache has aparently expired.
		if ($offset > 0 and $offset < $bundleSize and $bundleFileWasCreated) {
			return new HgResumeResponse(HgResumeResponse::RESET);
		}

		$actualChunkSize = 0;
		$data = "";
		if ($offset < $bundleSize) {
			// read the specified chunk of the bundle file
			$fileHandle = fopen($filename, "r");
			fseek($fileHandle, $offset);
			$data = fread($fileHandle, $chunkSize);
			fclose($fileHandle);
			$actualChunkSize = mb_strlen($data, "8bit");
		}

		// construct and return the response
		$response = new HgResumeResponse(HgResumeResponse::SUCCESS);
		$response->Values = array(
				'bundleSize' => $bundleSize,
				'chunkSize' => $actualChunkSize,
				'transId' => $transId);
		$response->Content = $data;
		return $response;
	}

	function getTip($repoId) {
		$availability = $this->isAvailable();
		if ($availability->Code == HgResumeResponse::NOTAVAILABLE) {
			return $availability;
		}
		try {
			$hg = new HgRunner("{$this->RepoBasePath}/$repoId");
			$revisionList = $hg->getRevisions(0, 1);
			$response = array('Tip' => $revisionList[0]);
			$hgresponse = new HgResumeResponse(HgResumeResponse::SUCCESS, $response);
		} catch (Exception $e) {
			$response = array('Error' => substr($e->getMessage(), 0, 1000));
			$hgresponse = new HgResumeResponse(HgResumeResponse::FAIL, $response);
		}
		return $hgresponse;
	}

	function getRevisions($repoId, $offset, $quantity) {
		$availability = $this->isAvailable();
		if ($availability->Code == HgResumeResponse::NOTAVAILABLE) {
			return $availability;
		}
		try {
			$hg = new HgRunner("{$this->RepoBasePath}/$repoId");
			$revisionList = $hg->getRevisions($offset, $quantity);
			$hgresponse = new HgResumeResponse(HgResumeResponse::SUCCESS, array(), implode("|",$revisionList));
		} catch (Exception $e) {
			$response = array('Error' => substr($e->getMessage(), 0, 1000));
			$hgresponse = new HgResumeResponse(HgResumeResponse::FAIL, $response);
		}
		return $hgresponse;
	}

	function finishPushBundle($transId) {
		//return; // for testing only - remove me
		$bundle = new BundleHelper($transId);
		if ($bundle->cleanUpFiles()) {
			return new HgResumeResponse(HgResumeResponse::SUCCESS);
		} else {
			return new HgResumeResponse(HgResumeResponse::FAIL);
		}
	}

	function finishPullBundle($transId) {
		$bundle = new BundleHelper($transId);
		if ($bundle->hasProp("tip") and $bundle->hasProp("repoId")) {
			$repoPath = $this->RepoBasePath . "/" . $bundle->getProp("repoId");
			if (is_dir($repoPath)) { // a redundant check (sort of) to prevent tests from throwing that recycle the same transid
				$hg = new HgRunner($repoPath);
				// check that the repo has not been updated, since a pull was started
				if ($bundle->getProp("tip") != $hg->getTip()) {
					$bundle->cleanUpFiles();
					return new HgResumeResponse(HgResumeResponse::RESET);
				}
			}
		}
		if ($bundle->cleanUpFiles()) {
			return new HgResumeResponse(HgResumeResponse::SUCCESS);
		}
		return new HgResumeResponse(HgResumeResponse::FAIL);
	}

	function isAvailable() {
		if ($this->isAvailableAsBool()) {
			return new HgResumeResponse(HgResumeResponse::SUCCESS);
		}
		$maintenanceFilePath = $this->RepoBasePath . "/maintenance_message.txt";
		$message = file_get_contents($maintenanceFilePath);
		return new HgResumeResponse(HgResumeResponse::NOTAVAILABLE, array(), $message);
	}

	private function isAvailableAsBool() {
		$maintenanceFilePath = $this->RepoBasePath . "/maintenance_message.txt";
		if (file_exists($maintenanceFilePath) && filesize($maintenanceFilePath) > 0) {
			return false;
		}
		return true;
	}
}

?>