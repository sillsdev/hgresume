<?php

require_once(SRC_PATH . "HgExceptions.php");
require_once(SRC_PATH . "/BundleHelper.php");

class TestOfBundleHelper extends PHPUnit_Framework_TestCase {

    function testcleanUp_BundleFileExists_DeletesBundleFile() {
        $bundle = new BundleHelper(__FUNCTION__);
        $bundle->cleanUp();
        $bundleFilename = $bundle->getBundleFileName();
        file_put_contents($bundleFilename, "bundle data");
        $bundle->cleanUp();
        $this->assertFalse(is_file($bundleFilename));
    }

    function testConstructor_TransIdIsAlphaNumeric_NoException() {
        $bundle = new BundleHelper("thisIsAlphaNumeric");
    }

    /**
     * @expectedException ValidationException
     * @expectedExceptionMessage did not validate as alpha numeric!
     */
    function testConstructor_TransIdCodeInjection_ThrowsException() {
        $bundle = new BundleHelper("id; echo \"bad script!\"");
    }

    function testGetOffset_Unset_ReturnsZero() {
        $transId = __FUNCTION__;
        $bundle = new BundleHelper($transId);
        $bundle->cleanUp();
        $this->assertEquals(0, $bundle->getOffset());
    }

    function testSetGetOffset_SetThenGet_GetReturnsValueThatWasSet() {
        $transId = __FUNCTION__;
        $bundle = new BundleHelper($transId);
        $bundle->cleanUp();
        $sow = 5023;
        $bundle->setOffset($sow);
        $this->assertEquals($sow, $bundle->getOffset());
    }

    function testGetState_GetReturnsDefault() {
        $transId = __FUNCTION__;
        $bundle = new BundleHelper($transId);
        $this->assertEquals(BundleHelper::State_Start, $bundle->getState());
        $bundle->cleanUp();
    }

    function testSetGetState_GetReturnsSet() {
        $transId = __FUNCTION__;
        $bundle = new BundleHelper($transId);
        $bundle->setState(BundleHelper::State_Downloading);
        $this->assertEquals(BundleHelper::State_Downloading, $bundle->getState());
        $bundle->cleanUp();
    }

    function testSetGetHasProp_SetMultipleProps_GetPropsOkAndVerifyHasPropsOk() {
        $transId = __FUNCTION__;
        $bundle = new BundleHelper($transId);
        $bundle->cleanUp();
        $this->assertFalse($bundle->hasProp("tip"));
        $bundle->setProp("tip", "7890");
        $this->assertTrue($bundle->hasProp("tip"));
        $bundle->setProp("repoId", "myRepo");
        $this->assertEquals("7890", $bundle->getProp("tip"));
        $this->assertEquals("myRepo", $bundle->getProp("repoId"));
    }
}

?>
