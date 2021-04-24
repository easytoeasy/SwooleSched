<?php

namespace pzr\swoolesched;

class Logger
{

    public const DEBUG = 1;
    public const WARNING = 1 << 1;
    public const INFO = 1 << 2;
    public const ERROR = 1 << 3;

    public static $level = self::INFO;

    public static function getLevels()
    {
        return [
            self::DEBUG => 'DEBUG',
            self::WARNING => 'WARNING',
            self::INFO => 'INFO',
            self::ERROR => 'ERROR',
        ];
    }

    public static function getNames()
    {
        return array_flip(self::getLevels());
    }

    public static function getName($level)
    {
        return self::getLevels()[$level];
    }

    public static function debug($msg)
    {
        self::writeLog($msg, self::DEBUG);
    }

    public static function info($msg)
    {
        self::writeLog($msg, self::INFO);
    }

    public static function warning($msg)
    {
        self::writeLog($msg, self::WARNING);
    }

    public static function error($msg)
    {
        self::writeLog($msg, self::ERROR);
    }

    public static function writeLog($msg, $level = self::INFO)
    {
        // 日志级别低的则不记录
        if ($level < self::$level) {
            return;
        }
        if (is_string($msg))
            printf("%s [%s] %s" . PHP_EOL, date('Y-m-d H:i:s'), self::getName($level), $msg);
        else {
            echo date('Y-m-d H:i:s') . '[' . self::getName($level) . ']' . PHP_EOL;
            print_r($msg) . PHP_EOL;
        }
    }
}
