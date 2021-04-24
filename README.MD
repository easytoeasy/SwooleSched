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

缺点：

- 目前是每分钟启动一次cron（cron表达式的校验每分钟启动即可），会导致毫秒级任务动态更新后短时间内会出现两个同id的子进程。

Composer 安装依赖
```shell
composer require dragonmantank/cron-expression
composer require doctrine/dbal
composer require swoole/ide-helper
```


调试工具：

1、跟踪TCP的情况：`sudo tcpdump -i any tcp port 3499`
观察`keepalve`和非长连接的情况。

开启长连接之后，每隔45s会检活一次。和Swoole设置的每4s测试一次不同啊？？？
```
15:17:30.401999 IP localhost.sccip-media > localhost.49457: Flags [.], ack 1165, win 6361, options [nop,nop,TS val 1988918035 ecr 1988737025], length 0
15:18:15.664937 IP localhost.49457 > localhost.sccip-media: Flags [.], ack 5356, win 6296, length 0
...
15:18:15.664987 IP localhost.sccip-media > localhost.49457: Flags [.], ack 1165, win 6361, options [nop,nop,TS val 1988963079 ecr 1988737025], length 0
15:19:01.166886 IP localhost.49457 > localhost.sccip-media: Flags [.], ack 5356, win 6296, length 0
...
15:19:01.166937 IP localhost.sccip-media > localhost.49457: Flags [.], ack 1165, win 6361, options [nop,nop,TS val 1989008383 ecr 1988737025], length 0
15:19:46.428092 IP localhost.49457 > localhost.sccip-media: Flags [.], ack 5356, win 6296, length 0
...
```
持续时间：15:15:13 ~ 15:21:05


在mac系统上的配置也不一样：
```shell
# 我mac电脑
net.inet.tcp.keepidle: 7200000
net.inet.tcp.keepintvl: 75000
# 一般的配置
net.ipv4.tcp_keepalive_time = 7200  //每间隔2小时间检活1次，如果正常则下次在2小时发送一次。
net.ipv4.tcp_keepalive_probes = 9   //如果连续9次检活都有问题，则关闭连接。net.ipv4.tcp_keepalive_intvl = 75            //如果检活时异常，则每隔75s 再次检活。
```


2、https://github.com/swoole/yasd
`sudo php -e Processor.php 99`
因为是守护进程，不起作用。

3、观察进程的内存占用：`top -p pid` || mac `top -pid pid`



4、

