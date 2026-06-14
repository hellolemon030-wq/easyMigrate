<?php
namespace Zjk\EasyMigrate;

class AutoMigrateException extends \Exception
{

    /** @var string */
    public $lastErrorFile = '';
    /** @var initInstance */
    public $lastErrorInitInstall;
    public function __construct($message = "", $code = 0)
    {
        parent::__construct($message, $code);
    }

    /**
     * @param \Exception $e
     * @param initInstance $initInstace
     */
    static public function generateInstance($e,$initInstace, $lastErrorFile = '')
    {
        $e = new self($e->getMessage(), $e->getCode());
        $e->lastErrorInitInstall = $initInstace;
        $e->lastErrorFile = $lastErrorFile;
        return $e;
    }
}