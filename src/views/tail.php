<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:meld="https://github.com/Supervisor/supervisor">

<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<title id="title">Schedule Status</title>
</head>

<body>

	<div id="content">

		<?php

use Swoole\Timer;
use Swoole\Coroutine as Co;

if (empty($this->logfile) || !is_file($this->logfile)) {
			echo 'has no such file or directory ' . $this->logfile;
			return;
		}
		echo 'logfile of ' . $this->logfile . PHP_EOL;
		echo '<hr>';
		$size = $_GET['size'] ? intval($_GET['size']) : 1024;
		echo '<pre>';
		/**
		 * 每次tail都会产生一个子进程，并且会被父进程注册的信号接收到之后被回收。
		 * 关闭STDOUT|STDERR之后，再次tail文件无法收到日志的内容。
		 * 因为日志内容都会被写入到重定向的STDOUT。
		 */
		// echo `tail -20 $this->logfile`;
		/*  */
		// echo fread($this->stdout_fd, 1024);
		$start = (filesize($this->logfile) - $size) >= 0
			? filesize($this->logfile) - $size : 0;
		echo file_get_contents($this->logfile, false, null, $start, $size);
		// print_r(Timer::list());
		// var_dump(Co::list());
		echo '</pre>';
		?>

	</div>

</body>

</html>