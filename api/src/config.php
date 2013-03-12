<?php

$rootPath = dirname(__FILE__);

define('SourcePath', $rootPath);

define("API_VERSION", 2);
define('CACHE_PATH', "/var/cache/hgresume");

$repoSearchPaths = array("/var/vcs/public", "/var/vcs/private");
//$repoSearchPaths = array("/var/vcs/public");

?>
