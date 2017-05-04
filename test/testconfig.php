<?php

$rootPath = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR;

if (! defined('SRC_PATH')) {
    define('SRC_PATH', $rootPath . 'src/');
}
if (! defined('LIB_PATH')) {
    define('LIB_PATH', $rootPath . 'src/lib/');
}

if (!defined('TEST_PATH')) {
    define('TEST_PATH', $rootPath . 'test/');
}

require_once(SRC_PATH . 'config.php');

require_once SRC_PATH . 'vendor/autoload.php';

require_once TEST_PATH . 'HgRepoTestEnvironment.php';

//define("SOURCE_PATH", "/var/vcs/languageforge/");
//define("DESTINATION_PATH", "/var/www/languageforge.local/LFAPI/test/");

?>
