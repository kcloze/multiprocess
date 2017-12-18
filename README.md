# multiprocess


* [[readme in english]](README.en.md)
* 基于swoole的脚本管理，用于多进程和守护进程管理；
* 可轻松让普通脚本变守护进程和多进程执行；
* 进程个数可配置，可以根据配置一次性执行多条命令；
* 子进程异常退出时,主进程收到信号，自动拉起重新执行；
* 支持子进程平滑退出，防止重启服务对业务造成影响；
* 不限定编程语言，PHP/Python/Java/Golang/C#等脚本都可以管理

## 1. 场景

* PHP/python/js等脚本需要跑一个或多个脚本消费队列/计算等任务
* 实现脚本退出后自动拉起，防止消费队列不工作，影响业务
* 其实supervisor可以轻松做个事情，这个只是PHP的另一种实现，不需要换技术栈

## 2. 流程图
![流程图](flow.png)


## 3. 安装
* git clone https://github.com/kcloze/multiprocess.git
* composer install
* 根据自己业务配置,修改config.php


## 4. 配置实例
* 一次性执行多个命令
```
    'logPath'   => __DIR__ . '/log',
    'exec'      => [
        [
            'name'      => 'kcloze-test-1',
            'bin'       => '/usr/bin/php',
            'binArgs'   => [__DIR__ . '/test/test.php', 'oop', '123'],
            'workNum'   => 3,
        ],
        [
            'name'      => 'kcloze-test-2',
            'bin'       => '/usr/bin/php',
            'binArgs'   => [__DIR__ . '/test/test2.php', 'oop', '456'],
            'workNum'   => 5,
        ],
        [
            'name'      => 'kcloze-test-3',
            'bin'       => '/usr/bin/python',
            'binArgs'   => [__DIR__ . '/test/test3.py', 'oop', '369'],
            'workNum'   => 2,
        ],
    ],

```
## 5. 运行

### 5.1 启动
* chmod -R u+r log/
* php multiprocess.php start >> log/system.log 2>&1
### 5.2 平滑停止服务，根据子进程执行时间等待所有服务停止
* php multiprocess.php stop
### 5.3 强制停止服务[慎用]
* php multiprocess.php exit
### 5.4 强制重启
* php multiprocess.php restart >> log/system.log 2>&1
### 5.5 监控
* ps -ef|grep 'multi-process'

### 5.6 启动参数说明
```
NAME
      php multiprocess - manage multiprocess

SYNOPSIS
      php multiprocess -s command [options] -c config file path
          Manage multiprocess daemons.


WORKFLOWS


      help [command]
      Show this help, or workflow help for command.

      -s restart
      Stop, then start multiprocess master and workers.

      -s start 
      Start multiprocess master and workers.
      -s start -c ./config
      Start multiprocess with specail config file.


      -s stop
      Wait all running workers smooth exit, please check multiprocess status for a while.

      -s exit
      Kill all running workers and master PIDs.

```
## 6. 服务管理
### 启动和关闭服务,有两种方式:

#### 6.1 php脚本(主进程挂了之后,需要手动启动)
```
./multiprocess.php start|stop|exit|restart

```



#### 6.2 使用systemd管理(故障重启、开机自启动)
[更多systemd介绍](https://www.swoole.com/wiki/page/699.html)

```
1. 根据自己项目路径,修改 systemd/multiprocess.service
2. sudo cp -f systemd/multiprocess.service /etc/systemd/system/
3. sudo systemctl --system daemon-reload
4. 服务管理
#启动服务
sudo systemctl start multiprocess.service
#reload服务
sudo systemctl reload multiprocess.service
#关闭服务
sudo systemctl stop multiprocess.service
```


## 7. 系统状态

![监控图](monitor.png)

## 8. change log

#### 2017-11-30
* 彻底重构v2版本
* 增加exit启动参数，默认stop等待子进程平滑退出


## 9. 感谢

* [swoole](http://www.swoole.com/)

## 10. 联系

qq群：141059677

