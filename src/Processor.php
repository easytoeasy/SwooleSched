<?php

namespace pzr\swoolesched;

use Cron\CronExpression;
use Exception;
use Swoole\Process;
use Swoole\Coroutine as Co;
use Swoole\Event;
use Swoole\Timer;
use Throwable;

class Processor
{
    protected $jobs;
    protected $localip;
    protected $dbConfig;
    protected $backlog = 10;

    public $pidfile = '/var/run/swoolesched{%d}.pid';
    protected $logfile = '/var/log/swoolesched{%d}.log';
    protected $stdout  = '/var/log/stdout{%d}.log';

    protected $message = 'Init Process OK';
    protected $outofMin = 0;
    protected $extSeconds = 1;
    protected $childpids = array();
    /** 由于DB记录改变，内存中即将被删除的命令 */
    protected $beDelIds = array();
    /** 记录主进程启动的时间 */
    protected $createAt;
    // protected $logger;
    protected $level;
    /* 黑洞实现 */
    protected $stdout_fd;
    protected $stderr_fd;
    protected $nullfd;
    protected $timerIds = array();
    protected $socket;
    protected $tick = 1000; //时间事件(ms)+文件事件
    // protected $channel;
    /** 保存父进程退出之前的子进程pid */
    // protected $saveChildpids = __DIR__ . '/childsPids.pid';
    // protected $preChildpids = array();

    const INIFILE = './swoolesched.ini';

    public function __construct()
    {
        $this->createAt = date('Y-m-d H:i');
        $this->_getLocalip();
        $this->_parseIni();
        // $this->_initJobs();

        /* stdout 可以重定向，stderr无法重定向。*/
        if ($this->logfile) {
            $this->stdout_fd = fopen($this->logfile, 'wb');
            $this->stderr_fd = fopen($this->logfile, 'wb');
        } else {
            $this->stdout_fd = fopen('/dev/null', 'w');
            $this->stderr_fd = fopen('/dev/null', 'w');
        }
        /* /dev/null 可设置为 r+|w|w+|a|a+，不能设置为 r
         * 不设置为可写的话，在不设置子进程的stdout时，子进程无法启动。 */
        $this->nullfd = fopen('/dev/null', 'w');
        if (!is_resource($this->nullfd)) {
            Logger::error('nullfd created failed');
            exit(3);
        }

        Logger::$level = $this->level;
        Logger::info('host:' . HOST . ', port:' . PORT);
        // 本来想处理完READ事件之后，将response数据写入channel
        // 然后在WRITE事件中在从channel读取数据返回给HTTP。可是似乎不行，WHY？
        // $this->channel = new Co\Channel(1);
    }

    public function run()
    {
        if (is_file($this->pidfile)) {
            $pid = file_get_contents($this->pidfile);
            if ($this->isAlive($pid)) {
                Logger::info('master alive' . PHP_EOL);
                exit(0);
            }
            unset($pid);
        }

        $socket = new Co\Socket(AF_INET, SOCK_STREAM, 0);
        $socket->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
        $socket->setOption(SOL_SOCKET, SO_REUSEPORT, 1);
        // 不开启长连接，因为web请求并不多。但是调试要完整。调试结束
        // 开启keepalive后，start|stop 请求一直处于pedding。
        // 已处理：HTTP 返回的header多加一空行。可能是因为Swoole的读取按\r\n的原因。
        $socket->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);
        if ($socket->bind(HOST, PORT) === false) {
            socket_close($socket);
            Logger::info('socket errCode:' . $socket->errCode . PHP_EOL);
            exit(3);
        }

        /* backlog 解释
         * TCP 3次握手状态：SYN_SEND、SYN_RCV、ESTABLISHED
         * 在客户端发起连接时，服务端维持处于SYN_RCV状态的连接并且最大数量是backlog。
         * 如果超过backlog的数量那么客户端的连接会被拒绝。
         * 除了backlog设置的队列外，系统还维护了ESTABLISHED的队列。*/
        if ($socket->listen($this->backlog) === false) {
            socket_close($socket);
            Logger::info('socket errcode:' . $socket->errCode . PHP_EOL);
            exit(3);
        }

        /* Event::add会自动将底层改成非阻塞模式 */
        if (Event::add($socket, [$this, 'acceptHandler']) === false) {
            $socket->close();
            Logger::info('socket errcode:' . $socket->errCode . PHP_EOL);
            exit(3);
        }

        $sigHandler = function ($signo) use ($socket) {
            // if ($this->childpids)
            //     file_put_contents($this->saveChildpids, json_encode($this->childpids));

            // Swoole进程内使用exit会使进程立即退出。
            // exit(0);

            try {
                unlink($this->pidfile);
                Logger::info('master Process exit code ' . $signo);
                exit(0);
            } catch (Swoole\ExitException $e) {
                Logger::info($e->getMessage());
            }
        };

        /* Swoole 安全退出时会等待所有子进程退出再退出。*/
        Process::signal(SIGTERM, $sigHandler);
        Process::signal(SIGINT,  $sigHandler);
        Process::signal(SIGQUIT, $sigHandler);

        file_put_contents($this->pidfile, getmypid());

        // 会导致协程id一直增加，如果协程id写爆了怎么办？会重新从0开始吗？
        Timer::tick($this->tick, function () {
        });

        $wait = 60;
        $stamp = time();
        $next = 0;
        while (true) {
            do {
                if ($stamp >= $next) {
                    break;
                }
                /* dispatch 等待事件的发生，需要定一个超时时间才好。
                 * 但是API又没有找到设置超时时间的参数。在epollfd是支持定义超时时间的啊！
                 * 为什么在Swoole没找到呢？最后使用时间事件+文件事件。类似Redis。 */
                Event::dispatch();
                $stamp = time();
            } while ($stamp < $next);
            $next = $stamp + $wait;
            $this->_startJobs();
            // 1min+1s内执行不完则记录
            if (time() - $next > $wait + $this->extSeconds) {
                $this->outofMin++;
            }
        }
    }

    private function _startJobs()
    {
        go(function () {
            $this->syncFromDB();
            foreach ($this->jobs as &$job) {
                // 支持ms||s级任务,单位是ms
                if (is_numeric($job->cron)) {
                    if (isset($this->timerIds[$job->id]))
                        continue;
                    Logger::info('add timer event, job id is ' . $job->id);
                    // Timer内会自动创建协程。
                    Timer::tick($job->cron, function ($timerid) use ($job) {
                        if (!isset($this->timerIds[$job->id])) {
                            Logger::debug('timer id is ' . $timerid);
                            $this->timerIds[$job->id] = $timerid;
                        }
                        $this->fork($job);
                    });
                } else if ($this->isAllowedRun($job)) {
                    /*
                    由于在协程空间内 fork 进程会带着其他协程上下文，因此底层禁止了在 Coroutine 中使用 Process 模块。可以使用
                    System::exec() 或 Runtime Hook+shell_exec 实现外面程序运行
                    Runtime Hook+proc_open 实现父子进程交互通信
                    */
                    go(function () use ($job) {
                        $this->fork($job);
                    });
                }
            }
        });
    }

    protected function fork(Job $job)
    {
        if ($job->state == State::RUNNING || $job->state == State::DELETING) {
            return;
        }

        $desc[1] = $job->output ? ['file', $job->output, 'a'] : $this->nullfd;
        $desc[2] = $job->stderr ? ['file', $job->stderr, 'a'] : $this->nullfd;
        try {
            $proc = proc_open($job->command, $desc, $pipes);
            if ($proc) {
                $st = proc_get_status($proc);
                if ($st['running'] === false) {
                    $job->state = State::BACKOFF;
                    proc_close($proc);
                    return;
                }
                $job->pid = $st['pid'];
                $this->childpids[$job->pid] = $job->md5;
                $job->state = State::RUNNING;
                $job->refcount++;
                $job->uptime = date('H:i:s');
                // Logger::info($job->id . ' start');
                /* (1) 在非协程下，加上proc_close这行就会导致阻塞。
                *      PHP手册提到这个函数会等待直到结束。
                * (2) 但是在Swoole + 协程下，就是异步的了。
                *      记得：hook + proc_open */
                $code = proc_close($proc);
                // Logger::debug($job->id . ' proc_close exitCode: ' . $code);
                unset($this->childpids[$job->pid]);
                $job->pid = 0;
                $job->refcount--;
                $job->endtime = date('H:i:s');
                if ($code != 0) {
                    $job->state = State::UNKNOWN;
                } else {
                    $job->state = State::STOPPED;
                }
            } else {
                $job->state = State::FATAL;
            }
        } catch (Exception $e) {
            $job->state = State::FATAL;
            Logger::error($e->getMessage());
        }
        return;
    }

    protected function syncFromDB()
    {
        $db = new Db($this->dbConfig);
        $jobs = $db->getJobs();
        $this->servTags = $db->getServTags();

        // if ($this->preChildpids)
        //     foreach ($this->preChildpids as $pid => $md5) {
        //         if (!$this->isAlive($pid)) {
        //             ($this->jobs[$md5])->refcount--;
        //             unset($this->preChildpids[$pid]);
        //             if (empty($this->preChildpids)) {
        //                 unlink($this->saveChildpids);
        //             }
        //         }
        //     }


        if (empty($this->jobs)) {
            // 保护父进程退出后的子进程不会发生重复执行
            /*if (
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
            }*/
            $this->jobs = $jobs;
        }



        // DB里面变更的命令
        $newAdds = array_diff_key($jobs, $this->jobs);

        // 内存中等待被删除的命令
        $beDels = array_diff_key($this->jobs, $jobs);

        /**
         * 正在运行的任务不能清除
         * 主进程每分钟跑一次，此时待删除的子进程可能需要运行数分钟。但是每分钟都会
         * 执行到这里。直到待删除的子进程全部结束。
         * 
         * 增加了ms级定时任务之后，因为这个逻辑是每分钟执行。
         * 所以毫秒级的任务在改变之后，先直接把原来的定时任务删除。然后在新增
         */
        foreach ($beDels as $key => $value) {
            $c = &$this->jobs[$key];

            // 毫秒级任务直接从定时任务删除
            if (isset($this->timerIds[$c->id])) {
                $timerid = $this->timerIds[$c->id];
                if (Timer::clear($timerid)) {
                    Logger::debug('clear timer id is ' . $timerid);
                    unset($this->timerIds[$c->id]);
                }
            } else if ($c->refcount > 0) { // cron任务等待子任务跑完在删除
                $this->beDelIds[$c->id] = $c->id;
                $c->state = State::DELETING;
                continue;
            }

            if (in_array($c->id, $this->beDelIds))
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
            Logger::error("parse error: undefined module ");
            exit(3);
        }

        if (
            empty($ini['server_vars'][HOST]) ||
            !array_key_exists(SERVER_ID, $ini['server_id']) ||
            empty($ini['server_id'][SERVER_ID])
        ) {
            Logger::error("parser error:  invalid value");
            exit(3);
        }

        $this->pidfile = $ini['pidfile'] ?? '';
        $this->logfile = $ini['logfile'] ?? '';
        $this->output = $ini['stdout'] ?? '';
        $this->backlog = $ini['backlog'] ?? 16;
        $this->level = $ini['loglevel'] ?? '';
        if (!array_key_exists($this->level, Logger::getNames())) {
            $this->level = Logger::INFO;
        } else {
            $this->level = Logger::getNames()[$this->level];
        }

        $this->pidfile = str_replace('{%d}', SERVER_ID, $this->pidfile);
        $this->logfile = str_replace('{%d}', SERVER_ID, $this->logfile);
        $this->output = str_replace('{%d}', SERVER_ID, $this->output);
        $this->dbConfig = $ini['db'];

        define('SERVER_VAR_ID', $ini['server_vars'][HOST]);
        define('PORT', $ini['server_id'][SERVER_ID]);
        define('KEEPALIVE', boolval($ini['keepalive']) ?? false);

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
            Logger::error('localip:' . $localip);
            exit(3);
        }
        define('HOST', $localip);
    }

    // 注意如果监听了 SWOOLE_EVENT_READ 事件，而当前并未设置 read_callback，
    // 底层会直接返回 false，添加失败。SWOOLE_EVENT_WRITE 同理。
    public function acceptHandler($socket)
    {
        $client = $socket->accept();
        if (!Event::isset($client, SWOOLE_EVENT_READ))
            Event::add($client, [$this, 'readFromClient'], null, SWOOLE_EVENT_READ);
    }

    public function readFromClient($client)
    {
        $retval = $client->recv();
        // keepalive下 start|stop 处于pedding，然后重新请求HTTP会断开之前的连接。
        // 为什么在上一次触发read事件时却没有断开连接呢？
        if ($retval === false || $retval === '') {
            Event::del($client);
            $client->close();
        } else {
            // 这里一定要加个子协程，保证handle处理后能够响应HTTP
            // 特别是删除DB缓存涉及到IO，如果没有子协程则会被切到其他的协程。。
            go(function () use ($client, $retval) {
                Http::parseHttp($retval);
                $this->handle();
                // 开启长连接之后，点击按钮 start|stop client无法send
                // 好像是HTTP已经无法接收数据了。该怎么办呢？
                // 最后发现是因为开启长连接之后，返回的301 HTTP header 要多加一行换行。
                if ($client->checkLiveness() === false) {
                    Logger::debug('client has died');
                    Event::del($client);
                    return;
                }

                if (($ret = $client->send($this->display())) === false) {
                    if ($client->errCode == SOCKET_EAGAIN) {
                        $ret = $client->send($this->display());
                    }
                }
                if ($ret === false) {
                    Logger::error('client send errCode: ' . $client->errCode);
                }
                // 不开启keepalive，数据响应结束就关闭了。但是有 HTTP 的 max-age 缓存。
                // 开启了keepalive，就不能再del了，因为会把长连接断开了。
                if (!KEEPALIVE)
                    Event::del($client);
            });
        }
    }

    public function display()
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptName = in_array($scriptName, ['', '/']) ? '/index.php' : $scriptName;
        if (trim($scriptName) == '/index.html') {
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
            $info = $c->id ?? '';
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
                Logger::debug('flushed cache OK');
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
            case 'clearTimer':
                if (isset($this->timerIds[$c->id])) {
                    $timerId = $this->timerIds[$c->id];
                    if (Timer::clear($timerId)) {
                        Logger::info('clear timer id is ' . $timerId);
                        unset($this->timerIds[$c->id]);
                    }
                }
                break;
            default:
                break;
        }
    }

    public function delTree($dir)
    {
        return `rm -rf $dir`;
        // Logger::debug('start delTree');
        // $files = array_diff(scandir($dir), array('.', '..'));
        // Logger::debug('files: ' . print_r($files));
        // Logger::debug('dir:' . $dir);
        // foreach ($files as $file) {
        //     (is_dir("$dir/$file")) ? $this->delTree("$dir/$file") : unlink("$dir/$file");
        // }
        // return rmdir($dir);
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

    public function __destruct()
    {
        fclose($this->stdout_fd);
        fclose($this->stderr_fd);
        fclose($this->nullfd);
    }
}

// error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
require __DIR__ . '/../vendor/autoload.php';
date_default_timezone_set('Asia/Shanghai');
umask(0);

$server_id = $argv[1];
if (empty($server_id)) {
    echo ('invalid value of server_id' . PHP_EOL);
    exit(3);
}

define('SERVER_ID', $server_id);
unset($server_id);

/* 主动关闭了stdout|stderr，
* 但是如果未重定向stdout则在有输出的情况下主进程无法启动。
* 如果指定了logfile，后续的输出如echo/var_dump/print_r 等都会输出到logfile。
* 否则输出到 /dev/null */
fclose(STDOUT);
fclose(STDERR);

$obj = new Processor();

Co::set([
    'hook_flags' => SWOOLE_HOOK_ALL,
    //下面的也没见起作用
    // 'chroot' => '',
    // 'pid_file' => $obj->pidfile,
    // 'open_tcp_keepalive' => true,
    // 'tcp_keepidle' => 4,        //4s没有数据传输就进行检测
    // 'tcp_keepinterval' => 1,    //1s探测一次
    // 'tcp_keepcount' => 5,       //探测的次数，超过5次后还没回包close此连接
]);

set_exception_handler(function ($e) {
    Logger::error($e->getMessage);
});

set_error_handler(function (int $errno, string $errstr, $errfile, $errline) {
    Logger::error(sprintf("errno %s %s in %s on line %s", $errno, $errstr, $errfile, $errline));
});


/*
- 调用异步风格服务端程序的 start 方法，此种启动方式会在事件回调中创建协程容器，参考 enable_coroutine。
- 调用 Swoole 提供的 2 个进程管理模块 Process 和 Process\Pool 的 start 方法，此种启动方式会在进程启动的时候创建协程容器，参考这两个模块构造函数的 enable_coroutine 参数。
- 其他直接裸写协程的方式启动程序，需要先创建一个协程容器 (Coroutine\run() 函数，可以理解为 java、c 的 main 函数)

@see https://wiki.swoole.com/#/process/process
*/
Process::daemon(); //开启协程后必须放在new之前。
$process = new Process(function () use ($obj) {
    $obj->run();
}, false, 0, true);
$process->name('SwooleSched ' . SERVER_ID);
$process->start();
