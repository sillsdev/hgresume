<?php

require_once('src/RestServer.php');
require_once('src/HgResumeAPI.php');

$repoPath = "/var/vcs/public";
//$repoPath = sys_get_temp_dir() . "/hgresume_repoTestEnvironment";
$api = new HgResumeAPI($repoPath);
$restServer = new RestServer($api, true);

$restServer->url = $_SERVER['REQUEST_URI'];
$restServer->args = $_REQUEST;
$restServer->postData = file_get_contents("php://input");

$restServer->handle();

?>