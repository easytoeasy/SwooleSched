<?php

use PHPUnit\Framework\TestCase;
use pzr\swoolesched\Logger;

include __DIR__ . "/../../vendor/autoload.php";

final class TestLogger extends TestCase
{

    public function testWarning()
    {
        Logger::$level = Logger::WARNING;
        Logger::warning('warning');
        Logger::info('info');
        Logger::error('error');
    }

    public function testInfo()
    {
        Logger::$level = Logger::INFO;
        Logger::warning('warning');
        Logger::info('info');
        Logger::error('error');
    }

}