
CREATE TABLE IF NOT EXISTS `scheduler_jobs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '命令名称用途',
  `command` varchar(1000) NOT NULL DEFAULT '' COMMENT '执行的命令',
  `command_md5` varchar(32) NOT NULL DEFAULT '' COMMENT '命令md5',
  `server_id` int(11) NOT NULL DEFAULT '0' COMMENT '执行的机器编号',
  `cron` varchar(200) NOT NULL DEFAULT '' COMMENT 'cron 表达式',
  `output` varchar(1000) NOT NULL DEFAULT '' COMMENT '输出定向到某个文件',
  `stderr` varchar(1000) NOT NULL DEFAULT '' COMMENT '异常定向到某个文件',
  `max_concurrence` int(11) NOT NULL DEFAULT '1' COMMENT '同时运行的最大数量',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '状态 0:关闭 1:开启',
  `create_time` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '更新时间',
  `last_run_time` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '上次运行时间',
  `tag_id` int(11) NOT NULL DEFAULT '0' COMMENT '标签id',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_serverId_commandMd5` (`server_id`,`command_md5`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- 这里的server_id和jobs的server_id已经不是一回事了。
-- jobs的server_id是为了将不同的脚本分类执行，不至于在一个循环内执行太多
-- vars的server_id是服务器的标志，不同的服务器上对应的目录、命令都可能不同。
-- 正确的说，jobs的server_id字段名称应该换一个才对。
CREATE TABLE IF NOT EXISTS `scheduler_vars` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '变量名',
  `value` varchar(200) NOT NULL DEFAULT '' COMMENT '值',
  `server_id` int(11) NOT NULL DEFAULT '0' COMMENT '所属机器编号',
  `create_time` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '修改时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_serverId_name` (`server_id`,`name`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

CREATE TABLE IF NOT EXISTS `scheduler_tags` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '',
  `create_time` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '更新时间',
  `update_time` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;