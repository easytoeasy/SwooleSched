<?php

/**/
$file = dirname(__DIR__) . '/src/swoolesched.ini';
$ret = parse_ini_file($file, true);
var_dump($ret);


/* 获取本机IP
$name = gethostname();
echo $name . PHP_EOL;
$ret = gethostbyname($name);
var_dump($ret);
*/