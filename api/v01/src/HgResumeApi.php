<?php

require_once("HgResumeResponse.php");
require_once("HgRunner.php");
require_once("BundleHelper.php");

class HgResumeAPI {
	var $RepoBasePaths;

	// Note: API_VERSION is defined in config.php

	function __construct($searchPaths) {
		// $searchPaths is an array of paths
		if (is_array($searchPaths)) {
			$this->RepoBasePaths = $searchPaths;
		}
		else {
			$this->RepoBasePaths = array($searchPaths);
		}
	}

	function pushBundleChunk($repoId, $bundleSize, $offset, $data, $transId) {
		$availability = $this->isAvailable();
		if ($availability->Code == HgResumeResponse::NOTAVAILABLE) {
			return $availability;
		}

		/********* Parameter validation and checking ************/
		// $repoId
		$repoPath = $this->getRepoPath($repoId);
		if (!$repoPath) {
			return new HgResumeResponse(HgResumeResponse::UNKNOWNID);
		}
		$hg = new HgRunner($repoPath);

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
				return new HgResumeResponse(HgResumeResponse::RECEIVED, array('sow' => $startOfWindow, 'Note' => 'server already had this data'));
			} else {
				return new HgResumeResponse(HgResumeResponse::FAIL, array('sow' => $startOfWindow, 'Error' => "data sent ($dataSize) with offset ($offset) falls after server's start of window ($startOfWindow)"));
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
		return $this->pullBundleChunkInternal($repoId, $baseHash, $offset, $chunkSize, $transId, false);

	}

	function pullBundleChunkInternal($repoId, $baseHash, $offset, $chunkSize, $transId, $waitForBundleToFinish) {
		try {
			$availability = $this->isAvailable();
			if ($availability->Code == HgResumeResponse::NOTAVAILABLE) {
				return $availability;
			}

			/********* Parameter validation and checking ************/
			// $repoId
			$repoPath = $this->getRepoPath($repoId);
			if (!$repoPath) {
				return new HgResumeResponse(HgResumeResponse::UNKNOWNID);
			}

			$hg = new HgRunner($repoPath);
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

			$bundleCreatedInThisExecution = false;
			$bundleFilename = $bundle->getBundleFileName();
			$bundleTimeFile = $bundle->getBundleTimeFileName();

			if (!is_file($bundleTimeFile)) {
				// this is the first pull request; make a new bundle

				if ($waitForBundleToFinish) {
					$hg->makeBundleAndWaitUntilFinished($baseHash, $bundleFilename, $bundleTimeFile);

				} else {
					$hg->makeBundle($baseHash, $bundleFilename, $bundleTimeFile);
				}
				$bundleCreatedInThisExecution = true;
			}

			// if the client requests an offset greater than 0, but the bundle needed to be created on this request,
			// send the RESET response since the server's bundle cache has aparently expired.
			if ($offset > 0 and $bundleCreatedInThisExecution) {
				return new HgResumeResponse(HgResumeResponse::RESET);
			}

			$response = new HgResumeResponse(HgResumeResponse::SUCCESS);
			if ($bundle->isBundleCreated()) {

				if ($bundle->isBundleValid()) {
					if ($bundleCreatedInThisExecution) {
						$bundle->setProp("tip", $hg->getTip());
						$bundle->setProp("repoId", $repoId);
					}
					$data = $this->getChunk($bundleFilename, $chunkSize, $offset);
					$response->Values = array(
							'bundleSize' => filesize($bundleFilename),
							'chunkSize' => mb_strlen($data, "8bit"),
							'transId' => $transId);
					$response->Content = $data;
					if ($offset > filesize($bundleFilename)) {
						throw new Exception("offset $offset is greater than or equal to bundleSize " . filesize($bundleFilename));
					}
				} else {
					$response = new HgResumeResponse(HgResumeResponse::FAIL);
					$response->Values = array('Error' => substr(file_get_contents($bundleTimeFile), 0, 1000));
				}
			} else { // bundle creation is in progress
				// loop indefinitely until we can return a chunk of data
				// see V02 for a smarter way to handle this using the INPROGRESS response
				while (true) {
					clearstatcache();
					if ($this->canGetChunkBelowBundleSize($bundleFilename, $chunkSize, $offset)) {
						$data = $this->getChunk($bundleFilename, $chunkSize, $offset);
						$response->Values = array(
								'bundleSize' => filesize($bundleFilename),
								'chunkSize' => mb_strlen($data, "8bit"),
								'transId' => $transId);
						$response->Content = $data;
						break;
					}
					sleep(1);
				}
			}
			return $response;

		} catch (Exception $e) {
			$response = array('Error' => substr($e->getMessage(), 0, 1000));
			return new HgResumeResponse(HgResumeResponse::FAIL, $response);
		}
	}

	private static function canGetChunkBelowBundleSize($filename, $chunkSize, $offset) {
		if (is_file($filename) and $offset + $chunkSize < filesize($filename)) {
			return true;
		}
		return false;
	}

	private static function getChunk($filename, $chunkSize, $offset) {
		$data = "";
		if ($offset < filesize($filename)) {
			// read the specified chunk of the bundle file
			$fileHandle = fopen($filename, "r");
			fseek($fileHandle, $offset);
			$data = fread($fileHandle, $chunkSize); //fread can handle if there's less than $chunkSize data left to read
			fclose($fileHandle);
		}
		return $data;
	}

	// not currently used in C# client
	function getTip($repoId) {
		$availability = $this->isAvailable();
		if ($availability->Code == HgResumeResponse::NOTAVAILABLE) {
			return $availability;
		}
		try {
			$repoPath = $this->getRepoPath($repoId);
			if ($repoPath) {
				$hg = new HgRunner($repoPath);
				$revisionList = $hg->getRevisions(0,1);
				$response = array('Tip' => $revisionList[0]);
				$hgresponse = new HgResumeResponse(HgResumeResponse::SUCCESS, $response);
			}
			else {
				$hgresponse = new HgResumeResponse(HgResumeResponse::UNKNOWNID);
			}
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
			$repoPath = $this->getRepoPath($repoId);
			if ($repoPath) {
				$hg = new HgRunner($repoPath);
				$revisionList = $hg->getRevisions($offset, $quantity);
				$hgresponse = new HgResumeResponse(HgResumeResponse::SUCCESS, array(), implode("|",$revisionList));
			}
			else {
				$hgresponse = new HgResumeResponse(HgResumeResponse::UNKNOWNID);
			}
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
			$repoPath = $this->getRepoPath($bundle->getProp("repoId"));
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
		$message = file_get_contents($this->getMaintenanceFilePath());
		return new HgResumeResponse(HgResumeResponse::NOTAVAILABLE, array(), $message);
	}

	private function isAvailableAsBool() {
		$file = $this->getMaintenanceFilePath();
		if (file_exists($file) && filesize($file) > 0) {
			return false;
		}
		return true;
	}

	private function getMaintenanceFilePath() {
		return SourcePath . "/maintenance_message.txt";
	}

	private function getRepoPath($repoId) {
		if ($repoId) {
			foreach ($this->RepoBasePaths as $basePath) {
				$possibleRepoPath = "$basePath/$repoId";
				if (is_dir($possibleRepoPath)) {
					return $possibleRepoPath;
				}
			}
		}
		return "";
	}
}

?>
