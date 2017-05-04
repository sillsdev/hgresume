<?php

require_once('config.php');
require_once('lib/RestServer.php');
require_once('lib/HgResumeApi.php');

//$repoPath = sys_get_temp_dir() . "/hgresume_repoTestEnvironment";
$api = new HgResumeAPI($repoSearchPaths);

// second param is debug mode
$restServer = new RestServer($api, false);

$restServer->url = $_SERVER['REQUEST_URI'];
$restServer->args = $_REQUEST;
$restServer->postData = file_get_contents("php://input");

$restServer->handle();

?>
