# 基于Swoole的定时管理器

特点：

- 支持cron表达式
- 支持ms级任务
- 可定义每个子任务的上限
- 子任务集中式管理
- 动态更新子进程任务
- echo\print等输出可重定向到日志文件
- 支持keepalive
- 支持ssl（还没做）
- 可WEB管理子进程
- 灵活性高，定制化需求


Composer 安装依赖
```shell
composer require dragonmantank/cron-expression
composer require doctrine/dbal
composer require swoole/ide-helper
```


调试工具：

1、跟踪TCP的情况：`sudo tcpdump -i any tcp port 3499`
观察`keepalve`和非长连接的情况。

2、https://github.com/swoole/yasd
`sudo php -e Processor.php 99`
因为是守护进程，不起作用。

3、观察进程的内存占用：`top -p pid` || mac `top -pid pid`

4、

