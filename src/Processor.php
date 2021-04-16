<?php

namespace pzr\swoolesched;

use Cron\CronExpression;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Swoole\Process;
use Swoole\Coroutine as Co;
use Swoole\Event;
use Throwable;

class Processor
{
    protected $jobs;
    protected $localip;
    protected $dbConfig;
    protected $backlog = 10;

    protected $pidfile = '/var/run/swoolesched{%d}.pid';
    protected $logfile = '/var/log/swoolesched{%d}.log';
    protected $stdout  = '/var/log/stdout{%d}.log';

    protected $message = 'Init Process OK';
    protected $outofMin = -1;
    protected $extSeconds = 3;
    protected $childpids = array();
    /** 由于DB记录改变，内存中即将被删除的命令 */
    protected $beDelIds = array();
    /** 记录主进程启动的时间 */
    protected $createAt;
    protected $logger;
    /* 黑洞实现 */
    protected $fd;
    /** 保存父进程退出之前的子进程pid */
    // protected $saveChildpids = __DIR__ . '/childsPids.pid';
    // protected $preChildpids = array();

    const INIFILE = './swoolesched.ini';

    public function __construct()
    {
        global $STDOUT, $STDERR;
        $this->createAt = date('Y-m-d H:i');
        $this->_getLocalip();
        $this->_parseIni();
        $this->_initJobs();
        $this->logger = new Logger('SwooleSched');
        $this->logger->pushHandler(new StreamHandler($this->logfile), Logger::WARNING);
        $this->logger->info('host:' . HOST . ', port:' . PORT);
        // @see https://blog.csdn.net/xuxuer/article/details/84882846
        // 在主进程内的输出会被重定向到fd
        if ($this->logfile) {
            $fd = fopen($this->logfile, 'wb');
        } else {
            $fd = fopen('/dev/null', 'r');
        }
        if (!$fd) {
            exit(3);
        }
        $STDOUT = $fd;
        $STDERR = $fd;
        $this->fd = $fd;
    }

    public function run()
    {
        if (is_file($this->pidfile)) {
            $pid = file_get_contents($this->pidfile);
            if ($this->isAlive($pid)) {
                echo 'master alive' . PHP_EOL;
                exit(0);
            }
            unset($pid);
        }

        $socket = new Co\Socket(AF_INET, SOCK_STREAM, 0);
        $socket->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
        $socket->setOption(SOL_SOCKET, SO_REUSEPORT, 1);
        $socket->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);
        if ($socket->bind(HOST, PORT) === false) {
            echo 'socket errcode:' . $socket->errCode . PHP_EOL;
            $socket->close();
            fclose($this->fd);
            exit(3);
        }

        /* backlog 解释
         * TCP 3次握手状态：SYN_SEND、SYN_RCV、ESTABLISHED
         * 在客户端发起连接时，服务端维持处于SYN_RCV状态的连接并且最大数量是backlog。
         * 如果超过backlog的数量那么客户端的连接会被拒绝。*/
        if ($socket->listen($this->backlog) === false) {
            echo 'socket errcode:' . $socket->errCode . PHP_EOL;
            $socket->close();
            fclose($this->fd);
            exit(3);
        }

        /* Event::add会自动将底层改成非阻塞模式 */
        if (Event::add($socket, [$this, 'acceptHandler']) === false) {
            echo 'socket errcode:' . $socket->errCode . PHP_EOL;
            $socket->close();
            exit(3);
        }

        $sigHandler = function ($signo) use ($socket) {
            // if ($this->childpids)
            //     file_put_contents($this->saveChildpids, json_encode($this->childpids));

            // 协程内写日志，会导致协程切换。
            // $this->logger->warning('parent exitCode ' . $signo);

            // 进程内使用exit会使进程立即退出。
            // exit(0);

            try {
                $socket->close();
                fclose($this->fd);
                exit(0);
            } catch (Swoole\ExitException $e) {
                echo $e->getMessage() . "\n";
            }
        };

        /* Swoole 安全退出时会等待所有子进程退出再退出。*/
        Process::signal(SIGTERM, $sigHandler);
        Process::signal(SIGINT, $sigHandler);
        Process::signal(SIGQUIT, $sigHandler);

        file_put_contents($this->pidfile, getmypid());

        $wait = 60;
        $stamp = time();
        $next = 0;
        Co::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        while (true) {
            do {
                if ($stamp >= $next) {
                    break;
                }
                Event::dispatch();
                $stamp = time();
            } while ($stamp < $next);
            // 1min+5s内执行不完则记录
            if ($stamp - $next > $wait + $this->extSeconds) {
                $this->outofMin++;
            }
            // 类似黑洞
            // file_put_contents($this->stdout, '');
            $this->_startJobs();
            $next = $stamp + $wait;
        }
    }

    private function _startJobs()
    {
        foreach ($this->jobs as $md5 => $job) {
            if ($this->isAllowedRun($job)) {
                /* 协程不能引入传递？但如果我就是想修改job呢？ */
                go(function () use ($md5, &$job) {
                    if ($job->state == State::RUNNING) {
                        return;
                    }
                    // 这里是将子进程的输出重定向到了stdout文件，能不能重定向到黑洞呢？
                    $stdout = $job->output ?: $this->stdout;
                    $stderr = $job->stderr ?: $this->stdout;
                    $proc = proc_open('exec ' . $job->command, [
                        1 => ['file', $stdout, 'a'],
                        2 => ['file', $stderr, 'a'],
                    ], $pipes);
                    // $job = &$this->jobs[$md5];
                    if ($proc) {
                        $st = proc_get_status($proc);
                        if ($st['running'] === false) {
                            $job->state = State::BACKOFF;
                            proc_close($proc);
                            return;
                        }
                        $job->pid = $st['pid'];
                        $this->childpids[$job->pid] = $md5;
                        $job->state = State::RUNNING;
                        $job->refcount++;
                        $this->logger->info($job->id . ' start at ' . date('Y-m-d H:i'));
                        /* (1) 在非协程下，加上proc_close这行就会导致阻塞。
                        *      PHP手册提到这个函数会等待直到结束。
                        * (2) 但是在Swoole + 协程下，就是异步的了。
                        *      记得：hook + proc_open */
                        $code = proc_close($proc);
                        unset($this->childpids[$job->pid]);
                        $job->pid = 0;
                        $job->state = State::STOPPED;
                        $job->refcount--;
                    } else {
                        $job->state = State::FATAL;
                    }
                });
            }
        }
    }

    protected function syncFromDB()
    {
        $db = new Db($this->dbConfig);
        $jobs = $db->getJobs();
        $this->servTags = $db->getServTags();

        if ($this->preChildpids)
            foreach ($this->preChildpids as $pid => $md5) {
                if (!$this->isAlive($pid)) {
                    ($this->jobs[$md5])->refcount--;
                    unset($this->preChildpids[$pid]);
                    if (empty($this->preChildpids)) {
                        unlink($this->saveChildpids);
                    }
                }
            }

        /*
        if (empty($this->jobs)) {
            // 保护父进程退出后的子进程不会发生重复执行
            if (
                is_file($this->saveChildpids) &&
                ($saveChildPids = file_get_contents($this->saveChildpids))
            ) {
                $saveChildPids = json_decode($saveChildPids, true);
                foreach ($saveChildPids as $pid => $md5) {
                    if (isset($jobs[$md5]) && $this->isAlive($pid)) {
                        ($jobs[$md5])->refcount++;
                        ($jobs[$md5])->pid = $pid;
                        ($jobs[$md5])->state = State::WAITING;
                        // 不是当前父进程的子进程，保证还能够refcount--
                        $this->preChildpids[$pid] = $md5;
                    }
                }
            }
            $this->jobs = $jobs;
        } */



        // DB里面变更的命令
        $newAdds = array_diff_key($jobs, $this->jobs);

        // 内存中等待被删除的命令
        $beDels = array_diff_key($this->jobs, $jobs);

        /**
         * 正在运行的任务不能清除
         * 主进程每分钟跑一次，此时待删除的子进程可能需要运行数分钟。但是每分钟都会
         * 执行到这里。直到待删除的子进程全部结束。
         */
        foreach ($beDels as $key => $value) {
            $c = &$this->jobs[$key];
            if ($c->refcount > 0) {
                $this->beDelIds[$c->id] = $c->id;
                $c->state = State::DELETING;
                continue;
            }
            unset($this->beDelIds[$c->id]);
            unset($this->jobs[$key]);
        }

        /** 
         * 子任务的某些字段值被更改后，会重新生成md5值。主进程会重新加载这个命令到内存并且执行。
         * 为了防止同一个id的命令被多次执行，在即将执行命令之前先按id排重。
         */
        foreach ($newAdds as $key => $value) {
            $value->state = State::WAITING;
            $this->jobs[$key] = $value;
        }

        unset($jobs, $newAdds, $beDels, $db);
    }

    /**
     * 允许最大并行子进程
     *
     * @param Job $c
     * @return bool
     */
    protected function isAllowedRun(Job $c)
    {
        // 该id对应的原子进程等待被删除，此时不能启动此id下的命令
        // if (in_array($c->id, $this->beDelIds)) {
        //     return false;
        // }
        if ($c->state == State::STARTING) {
            return true;
        }
        // 说明设置的定时任务内没跑完
        $cron = new CronExpression($c->cron);
        if (!$cron->isDue()) {
            return false;
        }
        if ($c->state == State::RUNNING) {
            $c->outofCron++;
        }
        if ($c->refcount >= $c->max_concurrence) {
            return false;
        }
        return true;
    }

    private function _parseIni()
    {
        $ini = parse_ini_file(self::INIFILE);
        if (
            !isset($ini['server_vars']) ||
            !isset($ini['db']) ||
            !isset($ini['server_id'])
        ) {
            echo ("parse error: undefined module \n");
            exit(3);
        }

        if (
            empty($ini['server_vars'][HOST]) ||
            !array_key_exists(SERVER_ID, $ini['server_id']) ||
            empty($ini['server_id'][SERVER_ID])
        ) {
            echo ("parser error:  invalid value \n");
            exit(3);
        }

        if ($ini['pidfile'])
            $this->pidfile = $ini['pidfile'];
        if ($ini['logfile'])
            $this->logfile = $ini['logfile'];
        if ($ini['stdout'])
            $this->output = $ini['stdout'];
        if (isset($ini['backlog']))
            $this->backlog = $ini['backlog'];

        $this->pidfile = str_replace('{%d}', SERVER_ID, $this->pidfile);
        $this->logfile = str_replace('{%d}', SERVER_ID, $this->logfile);
        $this->output = str_replace('{%d}', SERVER_ID, $this->output);
        $this->dbConfig = $ini['db'];

        define('SERVER_VAR_ID', $ini['server_vars'][HOST]);
        define('PORT', $ini['server_id'][SERVER_ID]);

        unset($ini);
    }

    private function _initJobs()
    {
        $db = new Db($this->dbConfig);
        $this->jobs = $db->getJobs();
        $this->servTags = $db->getServTags();
        unset($db);
    }

    private function _getLocalip()
    {
        $localip = gethostbyname(gethostname());
        if (count(explode('.', $localip)) != 4) {
            echo 'get localip:' . $localip . PHP_EOL;
            exit(3);
        }
        define('HOST', $localip);
    }

    public function acceptHandler($socket)
    {
        $client = $socket->accept();
        Event::add($client, [$this, 'readFromClient'], null, SWOOLE_EVENT_READ);
    }

    public function readFromClient($client)
    {
        $retval = $client->recv();
        if ($retval === false || $retval === '') {
            Event::del($client);
            // $client->close();
        } else {
            go(function () use ($client, $retval) {
                Http::parseHttp($retval);
                $this->handle();
                $client->send($this->display());
                // 没有keepalive，数据响应结束就关闭了。
                // 但是启用了 HTTP 的 max-age 缓存。
                // Event::del($client);
            });
        }
    }

    public function display()
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $scriptName = in_array($scriptName, ['', '/']) ? '/index.php' : $scriptName;
        if ($scriptName == '/index.html') {
            $location = sprintf("%s://%s:%s", 'http', HOST, PORT);
            return Http::status_301($location);
        }

        if ($scriptName == '/stderr.html') {
            $location = sprintf(
                "%s://%s:%s/stderr.php?md5=%s&type=%d",
                'http',
                HOST,
                PORT,
                $_GET['md5'],
                $_GET['type']
            );
            return Http::status_301($location);
        }

        $sourcePath = Http::$basePath . $scriptName;
        if (!is_file($sourcePath)) {
            return Http::status_404();
        }

        try {
            ob_start(null, null, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_REMOVABLE);
            require $sourcePath;
            $response = ob_get_contents();
            ob_end_clean();
        } catch (Throwable $e) {
            $response = $e->__toString();
        }

        unset($sourcePath);
        return Http::status_200($response);
    }

    public function handle()
    {
        $md5 = isset($_GET['md5']) ? $_GET['md5'] : '';
        $action = isset($_GET['action']) ? $_GET['action'] : '';
        if ($md5 && isset($this->jobs[$md5])) {
            $c = &$this->jobs[$md5];
        }

        if (!empty($action)) {
            $info = $c ? $c->id : '';
            $this->message = sprintf("%s %s at %s", $action, $info, date('Y-m-d H:i:s'));
        }

        switch ($action) {
            case 'start':
                if (!in_array($c->state, State::runingState())) {
                    $c->state = State::STARTING;
                }
                break;
            case 'stop':
                if ($c->state == State::RUNNING && $c->pid) {
                    $rs = Process::kill($c->pid, SIGTERM);
                    $this->message .= ' result:' . intval($rs);
                }
                break;
            case 'flush':
                $rs = $this->delTree(__DIR__ . '/cache');
                $this->message .= ' result:' . intval($rs);
                break;
            case 'clear':
                $this->clearLog($this->logfile);
                break;
            case 'clear_1':
                $this->clearLog($c->output);
                break;
            case 'clear_2':
                $this->clearLog($c->stderr);
                break;
            default:
                break;
        }
    }

    public function delTree($dir)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    public function clearLog($logfile)
    {
        if (empty($logfile) || !is_file($logfile)) {
            return;
        }
        $rs = file_put_contents($logfile, '');
        $this->message .= ' result:' . $rs;
    }

    protected function isAlive($pid)
    {
        if (empty($pid)) return false;
        return `ps aux | awk '{print $2}' | grep -w $pid`;
    }
}

// error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
require './../vendor/autoload.php';

fclose(STDOUT);
fclose(STDERR);

$server_id = $argv[1];
if (empty($server_id)) {
    echo 'invalid value of server_id' . PHP_EOL;
    exit(3);
}

define('SERVER_ID', $server_id);

// Process::signal(SIGCHLD, function ($sig) {
//     //必须为false，非阻塞模式
//     while ($ret = Process::wait(false)) {
//         echo "PID={$ret['pid']}\n";
//     }
// });

$obj = new Processor();
$process = new Process(function () use ($obj) {
    Process::daemon();
    cli_set_process_title('SwooleSched ' . SERVER_ID);
    $obj->run();
});
$process->start();
