<?php

require_once('HgExceptions.php');

class AsyncRunner
{
	private $_lockFile;

	public function __construct($runFilePath) {
		$this->_lockFile= "$runFilePath.async_run";
	}

	/**
	 * @param string $command The unescaped system command to run
	 */
	public function run($command) {
		$lockFilePath = $this->_lockFile;
		$command = escapeshellcmd($command);
		// The following command redirects all output (include output of the time command) to $finishFilename
		// The trailing ampersand makes the command run in the background
		// We touch the $finishFilename before execution to indicate that the command has started execution
		$command = "touch $lockFilePath; /usr/bin/time --append --output=$lockFilePath --format=\"AsyncCompleted: %E\" $command > $lockFilePath 2>&1 &";
		exec($command);
	}

	/**
	 * @return bool
	 */
	public function isRunning() {
		return file_exists($this->_lockFile);
	}

	/**
	 * @return bool
	 */
	public function isComplete() {
		$lockFilePath = $this->_lockFile;
		if (!file_exists($lockFilePath)) {
			throw new AsyncRunnerException("Lock file '$lockFilePath' not found, process is not running");
		}
		$data = file_get_contents($this->_lockFile);
		if (strpos($data, "AsyncCompleted") !== false) {
			return true;
		}
		return false;
	}

	/**
	 * 
	 * @throws AsyncRunnerException
	 * @return string
	 */
	public function getOutput() {
		if (!$this->isComplete()) {
			throw new AsyncRunnerException("Command on '$this->_lockFile' not yet complete.");
		}
		return file_get_contents($this->_lockFile);
	}

	public function cleanUp() {
		if (file_exists($this->_lockFile)) {
			unlink($this->_lockFile);
		}
	}

	/**
	 * Waits for up to 5s for the runner to complete
	 * @return boolean	True if complete, otherwise not complete yet
	 */
	public function waitForIsComplete() {
		for ($i = 0; $i < 5; $i++) {
			if ($this->isComplete()) {
				return true;
			}
			usleep(1000000);
		}
		return false;
	}
}

?>