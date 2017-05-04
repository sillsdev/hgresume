<?php

require_once(LIB_PATH . "AsyncRunner.php");

class TestOfAsyncRunner extends PHPUnit_Framework_TestCase {

    function testRunIsComplete_FalseThenTrue() {
        $runner = new AsyncRunner('/tmp/testFile');
        $runner->run('echo foo');
        $this->assertFalse($runner->isComplete());
        $runner->synchronize();
        $this->assertTrue($runner->isComplete());
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Lock file '/tmp/testFile.async_run' not found
     */
    function testIsComplete_WithNoRun_Throws() {
        $runner = new AsyncRunner('/tmp/testFile');
        $runner->cleanUp();
        $this->assertFalse($runner->isComplete());
    }

    function testIsRunning_FalseThenTrue() {
        $runner = new AsyncRunner('/tmp/testFile');
        $runner->cleanUp();
        $this->assertFalse($runner->isRunning());
        $runner->run('echo foo');
        $this->assertTrue($runner->isRunning());
    }

    function testCleanUp_FileRemoved() {
        $runner = new AsyncRunner('/tmp/testFile');
        $runner->run('echo foo');
        $this->assertTrue(file_exists('/tmp/testFile.async_run'));
        $runner->cleanUp();
        $this->assertFalse(file_exists('/tmp/testFile.async_run'));
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Command on '/tmp/testFile.async_run' not yet complete.
     */
    function testGetOutput_NotComplete_Throws() {
        $runner = new AsyncRunner('/tmp/testFile');
        $runner->run('echo abort');
        $runner->getOutput();
    }

    function testGetOutput_Complete_ReturnsOutput() {
        $runner = new AsyncRunner('/tmp/testFile');
        $runner->run('echo abort');
        $runner->synchronize();
        $data = $runner->getOutput();
        $this->assertContains('abort', $data);
    }

}

?>
