<?php

require_once('src/config.php');
require_once('src/RestServer.php');
require_once('src/HgResumeApi.php');

//$repoPath = sys_get_temp_dir() . "/hgresume_repoTestEnvironment";
$api = new HgResumeAPI($repoSearchPaths);

// in docker time might not be installed and it's used by
// AsyncRunner, just check at the start because throwing in AsyncRunner doesn't help much
if (!file_exists('/usr/bin/time')) {
    throw new Exception("/usr/bin/time, required by AsyncRunner");
}
// second param is debug mode
$restServer = new RestServer($api, false);

$restServer->url = $_SERVER['REQUEST_URI'];
$restServer->args = $_REQUEST;
$restServer->postData = file_get_contents("php://input");

$restServer->handle();

?>
