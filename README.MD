# 基于Swoole的定时管理器

可以支持毫秒级任务，秒级任务。
可以支持cron任务表达式。

Composer 安装依赖
```shell
composer require dragonmantank/cron-expression
composer require doctrine/dbal
composer require monolog/monolog
```

调试工具：

1、跟踪TCP的情况：`sudo tcpdump -i any tcp port 3499`
观察`keepalve`和非长连接的情况。

2、https://github.com/swoole/yasd
`sudo php -e Processor.php 99`

