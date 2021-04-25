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
    protected $backlog  = 10;

    public $pidfile     = '/var/run/swoolesched{%d}.pid';
    protected $logfile  = '/var/log/swoolesched{%d}.log';
    protected $stdout   = '/var/log/stdout{%d}.log';

    protected $message  = 'Init Process OK';
    protected $outofMin = 0;
    protected $extSeconds = 1;
    /** 由于DB记录改变，内存中即将被删除的命令 */
    protected $beDelIds = array();
    /** 记录主进程启动的时间 */
    protected $createAt;
    protected $stdout_fd;
    protected $stderr_fd;
    protected $nullfd;
    protected $timerIds = array();
    protected $socket;
    protected $tick = 1000; //时间事件(ms)
    // protected $channel;
    /** 保存父进程退出之前的子进程pid */
    // protected $saveChildpids = __DIR__ . '/childsPids.pid';
    // protected $preChildpids = array();

    const INIFILE = __DIR__ . '/swoolesched.ini';

    public function __construct()
    {
        $this->createAt = date('m-d H:i');
        $this->_getLocalip();
        $this->_parseIni();

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
    }

    public function run()
    {
        if (is_file($this->pidfile)) {
            $pid = file_get_contents($this->pidfile);
            if ($this->isAlive($pid)) {
                Logger::info('master alived and exitting' . PHP_EOL);
                exit(0);
            }
            unset($pid);
        }

        $socket = new Co\Socket(AF_INET, SOCK_STREAM, 0);
        $socket->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
        $socket->setOption(SOL_SOCKET, SO_REUSEPORT, 1);
        // 可能是因为Sw send时按 \r\n 解析，所以在 HTTP 的response header 多加了一空行
        // 否则 HTTP 无法正常解析header。
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
            try {
                unlink($this->pidfile);
                Logger::info('master Process exit code ' . $signo);
                exit(0);
            } catch (Swoole\ExitException $e) {
                Logger::error($e->getMessage());
            }
        };

        /* Swoole 安全退出时会等待所有子进程退出再退出。*/
        Process::signal(SIGTERM, $sigHandler);
        Process::signal(SIGINT,  $sigHandler);
        Process::signal(SIGQUIT, $sigHandler);

        file_put_contents($this->pidfile, getmypid());

        // 会导致协程id一直增加，如果协程id写爆了怎么办？会重新从0开始吗？
        // 不能使用 Timer::clearall()，因为必须保证这个时间事件存在。和
        // Event::dispatch() 结合使用。 
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
                // 时间事件+文件事件，防止文件事件长时间处于阻塞状态。
                Event::dispatch();
                $stamp = time();
            } while ($stamp < $next);
            $next = $stamp + $wait;
            $this->_startJobs();
            // 1min+1s内执行不完则记录
            if (time() - $next > $wait) {
                $this->outofMin++;
            }
        }
    }

    private function _startJobs()
    {
        go(function () {
            /* 先同步DB，再执行子进程。
             * Timer一旦启动后，则不再受60s轮询的影响。
             * 那么就要注意一个问题：syncDB将状态置成了DELETING，但是刚好此时Timer
             * 将状态又置成了其他，那么就会丢失DELETING状态 */
            $this->syncFromDB();
            foreach ($this->jobs as &$job) {
                if (!$this->isAllowedRun($job))
                    continue;

                // 支持ms级任务,单位是ms
                if (is_numeric($job->cron)) {
                    if (isset($this->timerIds[$job->id]))
                        continue;
                    // Timer内会自动创建协程。
                    Timer::tick($job->cron, function ($timerid) use ($job) {
                        if (!isset($this->timerIds[$job->id])) {
                            Logger::info('add timer event, job id is ' . $job->id);
                            $this->timerIds[$job->id] = $timerid;
                        }
                        if ($job->state == State::DELETING && $job->refcount == 0) {
                            Timer::clear($timerid);
                            unset($this->timerIds[$job->id]);
                            unset($this->beDelIds[$job->id]);
                            unset($this->jobs[$job->md5]);
                            unset($job);
                            return;
                        }
                        $this->fork($job);
                    });
                } else {
                    // 子任务的启动都会对应一个协程。
                    go(function () use ($job) {
                        $this->fork($job);
                    });
                }
            }
        });
    }

    protected function fork(Job $job)
    {
        // 处于待删除的旧子进程，不再启动。
        if ($job->state == State::DELETING || $job->state == State::RUNNING) {
            return;
        }

        $desc[1] = $job->output ? ['file', $job->output, 'a'] : $this->nullfd;
        $desc[2] = $job->stderr ? ['file', $job->stderr, 'a'] : $this->nullfd;
        try {
            $proc = proc_open($job->command, $desc, $pipes);
            if (!$proc) {
                $job->state = $job->state == State::DELETING ? State::DELETING : State::UNKNOWN;
                return;
            }
            $st = proc_get_status($proc);
            if ($st['running'] === false) {
                $job->state = $job->state == State::DELETING ? State::DELETING : State::STOPPED;
                proc_close($proc);
                return;
            }
            $job->pid = $st['pid'];
            $job->state = $job->state == State::DELETING ? State::DELETING : State::RUNNING;
            $job->refcount++;
            $job->uptime = date('H:i:s');
            /* (1) 一般情况下 proc_close 这行就会导致阻塞。PHP手册提到这个函数会等待直到结束。
             * (2) 但是在Swoole下，记得 hook + proc_open 异步处理 */
            $code = proc_close($proc);
            $job->pid = 0;
            $job->refcount--;
            $job->endtime = date('H:i:s');
            $job->state = $job->state == State::DELETING ? State::DELETING : State::STOPPED;
        } catch (Exception $e) {
            $job->state = $job->state == State::DELETING ? State::DELETING : State::FATAL;
            Logger::error($e->getMessage());
        }
    }

    protected function syncFromDB()
    {
        $db = new Db($this->dbConfig);
        $jobs = $db->getJobs();
        $this->servTags = $db->getServTags();
        if (empty($this->jobs)) {
            $this->jobs = $jobs;
        }
        // DB里面变更的命令
        $newAdds = array_diff_key($jobs, $this->jobs);
        // 内存中等待被删除的命令
        $beDels = array_diff_key($this->jobs, $jobs);

        foreach ($beDels as $key => $value) {
            $job = &$this->jobs[$key];
            $this->beDelIds[$job->id] = $job->id;
            $job->state = State::DELETING;
            // cron 待删除的任务在同步DB时就删除
            // Timer 待删除的任务在Timer定时任务中删除
            if ($job->refcount == 0 && !is_numeric($job->cron)) {
                unset($this->jobs[$key]);
            }
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
     * @param Job $job
     * @return bool
     */
    protected function isAllowedRun(Job $job)
    {
        // 该id对应的原子进程等待被删除，此时不能启动此id下的命令
        if (in_array($job->id, $this->beDelIds)) {
            return false;
        }

        if ($job->state == State::STARTING) {
            return true;
        }

        if (is_numeric($job->cron)) {
            if (isset($this->timerIds[$job->id])) {
                return false;
            }
        } else {
            // 说明设置的定时任务内没跑完
            $cron = new CronExpression($job->cron);
            if (!$cron->isDue()) {
                return false;
            }
        }

        if ($job->state == State::RUNNING) {
            $job->outofCron++;
        }
        if ($job->refcount >= $job->max_concurrence) {
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
        if (!array_key_exists($ini['loglevel'] ?? '', Logger::getNames())) {
            Logger::$level = Logger::INFO;
        } else {
            Logger::$level = Logger::getNames()[$ini['loglevel']];
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
            $job = &$this->jobs[$md5];
        }

        if (!empty($action)) {
            $info = $job->id ?? '';
            $this->message = sprintf("%s %s at %s", $action, $info, date('Y-m-d H:i:s'));
        }

        switch ($action) {
            case 'start':
                if (!in_array($job->state, State::runingState())) {
                    $job->state = State::STARTING;
                }
                break;
            case 'stop':
                if ($job->state == State::RUNNING && $job->pid) {
                    $rs = Process::kill($job->pid, SIGTERM);
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
                $this->clearLog($job->output);
                break;
            case 'clear_2':
                $this->clearLog($job->stderr);
                break;
            case 'clearTimer':
                if (isset($this->timerIds[$job->id])) {
                    $timerId = $this->timerIds[$job->id];
                    if (Timer::clear($timerId)) {
                        Logger::info('clear timer id is ' . $timerId);
                        unset($this->timerIds[$job->id]);
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
