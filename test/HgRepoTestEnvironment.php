<?php

class HgRepoTestEnvironment {
    public function __construct() {
        $this->BasePath = sys_get_temp_dir() . '/hgresume_repoTestEnvironment';
        self::recursiveDelete($this->BasePath);
        if (!is_dir($this->BasePath)) {
            mkdir($this->BasePath);
        }
    }

    /** @var string */
    public $Path;

    /** @var string */
    public $BasePath;

    /** @var string */
    public $RepoId;

    public function dispose() {
        self::recursiveDelete($this->BasePath);
        $maintFile = SRC_PATH . '/maintenance_message.txt';
        if (file_exists($maintFile)) {
            unlink($maintFile);
        }
    }

    public function makeRepo($zipfile) {
        $zip = new ZipArchive();
        $zip->open($zipfile);
        $this->RepoId = pathinfo($zipfile, PATHINFO_FILENAME);
        $this->Path = $this->BasePath . "/" . $this->RepoId;
        $zip->extractTo($this->Path);
        $zip->close();
    }

    private static function recursiveDelete($str){
        if(is_file($str)){
            //print "deleting $str\n";
            return @unlink($str);
        } elseif (substr($str, -1, 1) == '.') {
            return null;
        } elseif(is_dir($str)){
            $str = rtrim($str, '/');
            $pattern1 = $str . '/*';
            $pattern2 = $str . '/.*';
            $scan = glob("{" . "$pattern1,$pattern2" ."}", GLOB_BRACE);
            //print count($scan) . " items found to delete for $str:\n";
            //print_r($scan);
            foreach($scan as $index=>$path){
                self::recursiveDelete($path);
            }
            //print "deleting $str\n";
            return @rmdir($str);
        }
        return null;
    }
}
