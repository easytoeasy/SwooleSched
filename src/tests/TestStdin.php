<?php

use PHPUnit\Framework\TestCase;

include __DIR__ . "/../../vendor/autoload.php";



final class TestStdin
{

    public function testStd()
    {
        fclose(STDOUT);
        // fclose(STDERR);

        $stdout_file = __DIR__ . '/stdout.log';
        $stderr_file = __DIR__ . '/stderr.log';

        /* w|w+ 文件的数据会被重置。
         * a|a+|r|r+ 追加日志 
         * x|x+ 如果文件已存在则返回false
         * 正常的输出可以重定向到stdout，但是抛出的异常无法正确重定向。 */
        $stdout_fd = fopen($stdout_file, 'wb');
        // $stderr_fd = fopen($stderr_file, 'wb');

        echo 'this is stdout' . PHP_EOL;
        
        throw new Exception('do not write to stderr');

        fclose($stdout_fd);
        fclose($stderr_fd);
    }
}

(new TestStdin)->testStd();
