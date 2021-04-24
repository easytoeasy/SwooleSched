<?php

namespace pzr\swoolesched;

class State
{
    /* 子进程已结束 */
    const STOPPED = 0;
    /* 子进程等待启动 */
    const STARTING = 10;
    /* 子进程已启动 */
    const RUNNING = 20;
    /* 子进程未知原因启动失败 */
    const BACKOFF = 30;
    /* 子进程等待关闭 */
    const STOPPING = 40;
    /* 子进程由于错误退出 */
    const EXITED = 100;
    /* 子进程遇到了致命错误 */
    const FATAL = 200;
    /* 子进程被更新后，原先的那个处于等待删除阶段 */
    const DELETING = 300;
    /* 子进程被更新后，新的子进程处于等待启动阶段
     * 在这期间如果原任务id的refcount>0,则一直处于等待。*/
    const WAITING = 400;
    /* 未知原因导致子进程无法启动 */
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
