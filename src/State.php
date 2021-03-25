<?php

namespace pzr\swoolesched;

class State
{
    const STOPPED = 0;
    const STARTING = 10;
    const RUNNING = 20;
    const BACKOFF = 30;
    const STOPPING = 40;
    const EXITED = 100;
    const FATAL = 200;
    const DELETING = 300;
    const WAITING = 400;
    const UNKNOWN = 1000;

    public static $desc = [
        self::STOPPED => 'stopped',
        self::STARTING => 'starting',
        self::RUNNING => 'running',
        self::BACKOFF => 'backoff',
        self::STOPPING => 'stopping',
        self::EXITED => 'exited',
        self::FATAL => 'fatal',
        self::UNKNOWN => 'unknown',
        self::DELETING => 'deleting',
        self::WAITING => 'waiting',
    ];

    public static function desc($state)
    {
        return isset(self::$desc[$state]) ? self::$desc[$state] : $state;
    }

    public static function css($state)
    {
        if (in_array($state, [
            self::FATAL,
            self::UNKNOWN,
            self::EXITED,
        ])) {
            return 'error';
        } elseif (in_array($state, [
            self::RUNNING,
            self::DELETING,
        ])) {
            return 'running';
        } else {
            return 'nominal';
        }
    }

    public static function stopedState()
    {
        return [
            self::STOPPED,
            self::EXITED,
            self::FATAL,
            self::UNKNOWN,
            self::BACKOFF,
        ];
    }

    public static function runingState()
    {
        return [
            self::STARTING,
            self::RUNNING,
            self::DELETING,
        ];
    }
}
