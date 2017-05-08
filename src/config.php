<?php

$rootPath = realpath(__DIR__ .'/..') . DIRECTORY_SEPARATOR;

define('SourcePath', $rootPath.'src/');

define('API_VERSION', 3);
define('CACHE_PATH', '/var/cache/hgresume');

/** @var array $repoSearchPaths */
$repoSearchPaths = array('/var/vcs/public', '/var/vcs/private');
//$repoSearchPaths = array('/var/vcs/public');
