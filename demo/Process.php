<?php

use Cron\CronExpression;
use Doctrine\DBAL\DriverManager;
use Swoole\Process;

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
require './../vendor/autoload.php';
require './init.php';

Co::set(['hook_flags' => SWOOLE_HOOK_ALL]);

$jobs = array();

$params = [
    'dbname' => 'test',
    'user' => 'root',
    'password' => '123456',
    'host' => 'localhost',
    'port' => '3306',
    'charset' => 'utf8',
    'driver' => 'pdo_mysql',
];

$conn = DriverManager::getConnection($params);
$sql = "SELECT * FROM `scheduler_jobs` WHERE `server_id`=99 ";
$stmt = $conn->executeQuery($sql);
$jobs = $stmt->fetchAllAssociative();

$sql = "SELECT `name`,`value` FROM `scheduler_vars` WHERE `server_id`=1";
$stmt = $conn->executeQuery($sql);
$varstmp = $stmt->fetchAllAssociative();
$vars = [];
foreach ($varstmp as $v) {
    $vars['{' . $v['name'] . '}'] = $v['value'];
}

foreach ($jobs as &$v) {
    $v['command'] = str_replace(array_keys($vars), array_values($vars), $v['command']);
}

function acceptHandler($socket)
{
    $client = $socket->accept();
    Swoole\Event::add($client, 'readFromClient', null, SWOOLE_EVENT_READ);
}

function readFromClient($client)
{
    $retval = $client->recv();
    if ($retval === false || $retval === '') {
        Swoole\Event::del($client);
        $client->close();
    } else {
        go(function () use ($client, $retval) {
            global $jobs;
            parseHttp($retval);
            $path =  __DIR__ . $_SERVER['SCRIPT_NAME'];
            $response = Co\FastCGI\Client::call(
                HOST . ':' . 9000, // FPM监听地址, 也可以是形如 unix:/tmp/php-cgi.sock 的unixsocket地址
                $path,      // 想要执行的入口文件
                $jobs   // 附带的POST信息
            );
            $client->send(status_200($response));
        });
        Swoole\Event::del($client);
        /* 正常响应HTTP请求 */
        // Swoole\Event::set($client, null, 'writeToClient', SWOOLE_EVENT_WRITE);
    }
}

Swoole\Process::signal(SIGCHLD, function ($sig) {
    //必须为false，非阻塞模式
    while ($ret = Swoole\Process::wait(false)) {
        echo "PID={$ret['pid']}\n";
    }
});

$process = new Process(function () {
    // 蜕变为守护进程
    // Process::daemon();

    global $jobs;

    $socket = new Co\Socket(AF_INET, SOCK_STREAM, 0);
    $socket->bind(HOST, PORT);
    $socket->listen(128);

    /* Event::add会自动将底层改成非阻塞模式 */
    if (Swoole\Event::add($socket, 'acceptHandler') === false) {
        echo swoole_strerror(swoole_last_error(), 9) . PHP_EOL;
        exit(3);
    }
    $wait = 60;
    $stamp = time();
    $next = 0;
    while (true) {
        do {
            if ($stamp >= $next) {
                break;
            }
            Swoole\Event::dispatch();
            $stamp = time();
        } while ($stamp < $next);
        echo "start jobs \n";
        foreach ($jobs as $v) {
            $cron = new CronExpression($v['cron']);
            if ($cron->isDue()) {
                go(function() use ($v) {
                    $proc = proc_open('exec ' . $v['command'], [
                        1 => ['file', __DIR__ . '/stdout.log', 'a'],
                        2 => ['file', __DIR__ . '/stderr.log', 'a'],
                    ], $pipes);
                    if ($proc) {
                        $st = proc_get_status($proc);
                        /* (1) 在非协程下，加上proc_close这行就会导致阻塞。
                         *      PHP手册提到这个函数会等待直到结束。
                         * (2) 但是在Swoole + 协程下，就是异步的了。
                         *      记得：hook + proc_open */
                        proc_close($proc);
                    }
                });
            }
        }
        echo "end jobs \n";
        $next = $wait + $stamp;
    }
});
$process->start();
