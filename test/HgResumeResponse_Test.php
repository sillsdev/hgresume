<?php

use Lib\HgResumeResponse;

class TestOfHgResumeResponse extends PHPUnit_Framework_TestCase {

    function testConstructor_DefaultParams_VersionIsNotEmpty() {
        $response = new HgResumeResponse(HgResumeResponse::SUCCESS);
        $this->assertNotNull($response->Version);
    }
}
