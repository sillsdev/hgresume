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

	/**
	 * Pushes a chunk of binary $data at $offset in the stream for $transId in $repoId
	 * @param string $repoId
	 * @param int $bundleSize
	 * @param int $offset
	 * @param byte[] $data
	 * @param string $transId
	 * @return HgResumeResponse
	 */
	function pushBundleChunk($repoId, $bundleSize, $offset, $data, $transId) {
		$availability = $this->isAvailable();
		if ($availability->Code == HgResumeResponse::NOTAVAILABLE) {
			return $availability;
		}

		// ------------------
		// Check the input parameters
		// ------------------
		// $repoId
		$repoPath = $this->getRepoPath($repoId);
		if (!$repoPath) {
			return new HgResumeResponse(HgResumeResponse::UNKNOWNID);
		}
		$hg = new HgRunner($repoPath);

		// $offset
		if ($offset < 0 or $offset >= $bundleSize) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid offset'));
		}
		// $data
		$dataSize = mb_strlen($data, "8bit");
		if ($dataSize == 0) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'no data sent'));
		}
		if ($dataSize > $bundleSize - $offset) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'data sent is larger than remaining bundle size'));
		}
		// $bundleSize
		if (intval($bundleSize) < 0) {
			return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'negative bundle size'));
		}

		// ------------------
		// Good to go ...
		// ------------------

		$bundle = new BundleHelper($transId);

		// if the data sent falls before the start of window, mark it as received and reply with correct startOfWindow
		// Fail if there is overlap or a mismatch between the start of window and the data offset
		$startOfWindow = $bundle->getOffset();
		if ($offset != $startOfWindow) { // these are usually equal.  It could be a client programming error if they are not
			if ($offset < $startOfWindow) {
				return new HgResumeResponse(HgResumeResponse::RECEIVED, array('sow' => $startOfWindow, 'Note' => 'server received duplicate data'));
			} else {
				return new HgResumeResponse(HgResumeResponse::FAIL, array('sow' => $startOfWindow, 'Error' => "data sent ($dataSize) with offset ($offset) falls after server's start of window ($startOfWindow)"));
			}
		}

		// write chunk data to bundle file
		$bundleFile = fopen($bundle->getBundleFileName(), "a");
		fseek($bundleFile, $offset);
		fwrite($bundleFile, $data);
		fclose($bundleFile);

		$newSow = $offset + $dataSize;
		$bundle->setOffset($newSow);
		// for the final chunk; assemble the bundle and apply the bundle
		if ($newSow == $bundleSize) {
			try {
				$hg->unbundle($bundle->getBundleFileName()); // TODO make this async

				$responseValues = array('transId' => $transId);
				$response = new HgResumeResponse(HgResumeResponse::SUCCESS, $responseValues);
				$this->finishPushBundle($transId); // clean up bundle assembly cache
			} catch (Exception $e) {
				$bundle->setOffset(0);
				$responseValues = array('Error' => substr($e->getMessage(), 0, 1000));
				$responseValues['transId'] = $transId;
				$response = new HgResumeResponse(HgResumeResponse::RESET, $responseValues);
				//$this->finishPushBundle($transId); // clean up bundle assembly cache
			}
			return $response;
		} else {
			// received the chunk, but it's not the last one; we expect more chunks
			$responseValues = array('transId' => $transId, 'sow' => $newSow);
			return new HgResumeResponse(HgResumeResponse::RECEIVED, $responseValues);
		}
	}

	/**
	 *
	 * Requests to pull a chunk of $chunkSize bytes at $offset in the bundle from $repoId using the
	 * $transId to identify this transaction.
	 * @param string $repoId
	 * @param string $baseHash
	 * @param int $offset
	 * @param int $chunkSize
	 * @param string $transId
	 * @return HgResumeResponse
	 */
	function pullBundleChunk($repoId, $baseHash, $offset, $chunkSize, $transId) {
		return $this->pullBundleChunkInternal($repoId, $baseHash, $offset, $chunkSize, $transId, false);

	}

	function pullBundleChunkInternal($repoId, $baseHash, $offset, $chunkSize, $transId, $waitForBundleToFinish) {
		try {
			$availability = $this->isAvailable();
			if ($availability->Code == HgResumeResponse::NOTAVAILABLE) {
				return $availability;
			}

			// ------------------
			// Check the input parameters
			// ------------------
			// $repoId
			$repoPath = $this->getRepoPath($repoId);
			if (!$repoPath) {
				return new HgResumeResponse(HgResumeResponse::UNKNOWNID);
			}
			// $offset
			if ($offset < 0) {
				return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid offset'));
			}

			$hg = new HgRunner($repoPath); // REVIEW The hg based checks only need to be done once per transaction. Would be nice to move them inside the state switch CP 2012-06
			// $baseHash
			if (!$hg->isValidBase($baseHash)) {
				return new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => 'invalid baseHash'));
			}

			// ------------------
			// Good to go ...
			// ------------------
			// if the server's tip is equal to the baseHash requested, then no pull is necessary
			if ($hg->getTip() == $baseHash) {
				return new HgResumeResponse(HgResumeResponse::NOCHANGE);
			}

			$bundle = new BundleHelper($transId);
			$asyncRunner = new AsyncRunner($bundle->getBundleBaseFilePath());

			$bundleCreatedInThisExecution = false;
			$bundleFilename = $bundle->getBundleFileName();

			if (!$bundle->exists()) {
				// if the client requests an offset greater than 0, but the bundle needed to be created on this request,
				// send the RESET response since the server's bundle cache has aparently expired.
				if ($offset > 0) {
					return new HgResumeResponse(HgResumeResponse::RESET); // TODO Add in error information in here saying that the bundle isn't present CP 2012-06
				}
				// At this point we can presume that $offset == 0 so this is the first pull request; make a new bundle
				if ($waitForBundleToFinish) {
					$hg->makeBundleAndWaitUntilFinished($baseHash, $bundleFilename, $asyncRunner);
				} else {
					$hg->makeBundle($baseHash, $bundleFilename, $asyncRunner);
				}
				$bundle->setProp("tip", $hg->getTip());
				$bundle->setProp("repoId", $repoId);
				$bundle->setState(BundleHelper::State_MakingBundle);
			}

			$response = new HgResumeResponse(HgResumeResponse::SUCCESS);
			switch ($bundle->getState()) {
				case BundleHelper::State_MakingBundle:
					if ($asyncRunner->isComplete()) {
						if (BundleHelper::bundleOutputHasErrors($asyncRunner->getOutput())) {
							$response = new HgResumeResponse(HgResumeResponse::FAIL);
							$response->Values = array('Error' => substr(file_get_contents($bundleTimeFile), 0, 1000));
							return $response;
						}
						$bundle->setState(BundleHelper::State_Downloading);
					}
					// TODO turn this into a for loop $i = 0, 1 CP 2012-06
					clearstatcache(); // clear filesize() cache so that we can get accurate answers to the filesize() function
					if ($this->canGetChunkBelowBundleSize($bundleFilename, $chunkSize, $offset)) {
						$data = $this->getChunk($bundleFilename, $chunkSize, $offset);
						$response->Values = array(
								'bundleSize' => filesize($bundleFilename),
								'chunkSize' => mb_strlen($data, "8bit"),
								'transId' => $transId);
						$response->Content = $data;
					} else {
						sleep(4);
						clearstatcache(); // clear filesize() cache
						// try a second time to get a chunk below bundle size
						if ($this->canGetChunkBelowBundleSize($bundleFilename, $chunkSize, $offset)) {
							$data = $this->getChunk($bundleFilename, $chunkSize, $offset);
							$response->Values = array(
									'bundleSize' => filesize($bundleFilename),
									'chunkSize' => mb_strlen($data, "8bit"),
									'transId' => $transId);
							$response->Content = $data;
						} else {
							$response = new HgResumeResponse(HgResumeResponse::INPROGRESS);
						}
					}
					break;
				case BundleHelper::State_Downloading:
					$data = $this->getChunk($bundleFilename, $chunkSize, $offset);
					$response->Values = array(
							'bundleSize' => filesize($bundleFilename),
							'chunkSize' => mb_strlen($data, "8bit"),
							'transId' => $transId);
					$response->Content = $data;
					if ($offset > filesize($bundleFilename)) {
						throw new Exception("offset $offset is greater than or equal to bundleSize " . filesize($bundleFilename));
					}
					break;
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
