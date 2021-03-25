<?php

namespace pzr\swoolesched;

class Job extends BaseObject
{
    public $id;                 //自增id
    public $name;               //命令名称用途
    public $command;            //执行的命令
    public $cron;               //cron表达式
    public $output;             //输出定向文件
    public $stderr;             //输出错误文件
    public $state;              //状态
    public $pid;                //进程ID
    public $uptime;             //启动时间
    public $endtime;            //结束时间
    public $tag_id;             //标签ID，可用于快速搜索
    public $process;
    /** 
     * id、name、command、cron、output、stderr、tag_id、server_id、max_concurrence
     * 的md5值。目的是当这些字段发生变化时，可以将原来内存的删除掉，在新增修改后的命令到内存。
     */
    public $md5;
    /** 
     * 目前执行子进程的时候这个字段并未设置值。
     * 因为现在的做法是将command的工作目录通过vars的变量配置，
     * 从而在web上可以更直观的输出完整的命令。
     */
    public $directory;
    /**
     * Jobs的server_id和vars的server_id不是一个概念。
     * Jobs的server_id是把所有的命令分类，然后分批管理。
     * 而vars的server_id是不同机器的编号。从而可以分布式管理。
     */
    public $server_id;
    /**
     * 该命令可同时启动进程最大数量，如果为0则不会启动。
     */
    public $max_concurrence;
    /**
     * 为了限定`max_concurrence`当前启动的进程数。
     * 当refcount为0表示当前子进程未启动。
     * 其他则表示正有多少个进程在执行该命令。
     */
    public $refcount = 0;
    /**
     * 如果该进程在表达式cron内未执行完毕，则计数提示。
     * 但是如果web手动启动命令后可能导致计数增加。
     */
    public $outofCron = 0;


    /**
     * Get the value of id
     */ 
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set the value of id
     *
     * @return  self
     */ 
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }
}