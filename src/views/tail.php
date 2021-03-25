<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:meld="https://github.com/Supervisor/supervisor">

<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<title id="title">Schedule Status</title>
</head>

<body>

	<div id="content">

		<?php
		if (empty($this->logfile) || !is_file($this->logfile)) {
			echo 'has no such file or directory ' . $this->logfile;
			return;
		}
		echo 'logfile of ' . $this->logfile . PHP_EOL;
		echo '<hr>';
		$size = isset($_GET['size']) && $_GET['size'] > 0 ? intval($_GET['size']) : 20;
		echo '<pre>';
		/**
		 * 每次tail都会产生一个子进程，并且会被父进程注册的信号接收到之后被回收。
		 */
		echo `tail -{$size} $this->logfile`;
		echo '</pre>';
		?>

	</div>

</body>

</html>