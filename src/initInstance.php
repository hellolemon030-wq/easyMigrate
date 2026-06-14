<?php
namespace Zjk\EasyMigrate;

use Exception;


class initInstance {

    CONST INI_FILE_NAME = "easyMigrate.ini";

    public $projectId = "";
    public $projectDir = "";
    public $dbTag = "";

    public $_db;

    public function __construct(){

    }

    public function getProjectId(){
        return $this->projectId;
    }
    public function getProjectDir(){
        return $this->projectDir;
    }
    public function getDbTag(){
        return $this->dbTag;
    }

    static public function makeConfigJson($projectId,$dbTag,$projectDir){
        $json = json_encode([
            "project_id" => $projectId,
            "db_tag" => $dbTag,
            "project_dir" => $projectDir,
        ]);
        return $json;
    }

    /**
     * @return self
     */
    static public function loadByProject($projectDir){
        $init = new self();
        $init->projectDir = $projectDir;
        $fileName = $projectDir . '/' . self::INI_FILE_NAME;
        if(!file_exists($fileName)){
            throw new Exception("文件{$fileName}不存在");
        }
        $ini = parse_ini_file($fileName);
        $init->projectId = $ini['project_id'];
        $init->dbTag = $ini['db_tag'];
        $init->projectDir = $projectDir;
        return $init;
    }

    static public function initByJson($json){
        $init = new self();
        $json = json_decode($json,true);
        $init->projectId = $json['project_id'];
        $init->dbTag = $json['db_tag'];
        $init->projectDir = $json['project_dir'];
        return $init;
    }
}