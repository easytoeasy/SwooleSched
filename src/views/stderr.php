<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:meld="https://github.com/Supervisor/supervisor">

<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<title id="title">Schedule Status</title>
</head>

<body>

	<?php

	$md5 = isset($_GET['md5']) ? $_GET['md5'] : '';
	if (!isset($this->jobs[$md5])) {
		echo 'undefined Process';
		return;
	} else {
	?>

		<div id="content">
			<ul>
				<li>
					<a href="stderr.php?md5=<?= $_GET['md5'] ?>&type=2" name="Tail -f Stderr">Tail -f Stderr</a>
					&nbsp;&nbsp;
					<a href="stderr.html?md5=<?= $_GET['md5'] ?>&action=clear_2&type=2" name="Clear Stderr">Clear Stderr</a>
				</li>
				<li>
					<a href="stderr.php?md5=<?= $_GET['md5'] ?>&type=1" name="Tail -f Stderr">Tail -f Stdout</a>
					&nbsp;&nbsp;
					<a href="stderr.html?md5=<?= $_GET['md5'] ?>&action=clear_1&type=1" name="Clear Stderr">Clear Stdout</a>
				</li>
			</ul>
			<?php
			// 1stdout，2stderr
			$type = isset($_GET['type']) ? $_GET['type'] : 1;
			$c = $this->jobs[$md5];
			$typeCn = $type == 1 ? 'Stdout' : 'Stderr';
			$logfile = $type == 1 ? $c->output : $c->stderr;
			if (empty($logfile) || !is_file($logfile)) {
				echo 'has no such file or directory: ' . $logfile;
				return;
			}
			echo $typeCn . '	:' . $logfile . PHP_EOL;
			echo '<hr>';
			$size = isset($_GET['size']) && $_GET['size'] > 0 ? intval($_GET['size']) : 100;
			echo '<pre>';
			echo `tail -{$size}  $logfile`;
			echo '</pre>';
			?>

		</div>
	<?php } ?>

</body>

</html>