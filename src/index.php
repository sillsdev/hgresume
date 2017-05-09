<?php

use Lib\HgResumeApi;
use Lib\RestServer;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

//$repoPath = sys_get_temp_dir() . "/hgresume_repoTestEnvironment";
$api = new HgResumeApi($repoSearchPaths);

// second param is debug mode
$restServer = new RestServer($api, false);

$restServer->url = $_SERVER['REQUEST_URI'];
$restServer->args = $_REQUEST;
$restServer->postData = file_get_contents('php://input');

$restServer->handle();
