# multiprocess [[readme in english]](README.en.md)
* 基于swoole的脚本管理，用于多进程和守护进程管理
* 可轻松让普通PHP脚本变守护进程和多进程执行
* 进程个数可配置，可以根据配置一次性执行多条命令
* 子进程异常退出时,自动重启
* 主进程异常退出时,子进程在干完手头活后退出(平滑退出)


## 场景

* PHP需要跑一个或多个cli脚本消费队列（常驻）
* 实现脚本退出后自动拉起，防止消费队列不工作，影响业务
* 其实supervisor可以轻松做个事情，这个只是PHP的另一种实现，不需要换技术栈

## 安装
* git clone https://github.com/kcloze/multiprocess.git
* composer install
* 根据自己业务配置,修改config.php


## 配置实例
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
    ],

```
## 运行

### 1.启动
* chmod -R u+r log/
* php run.php start >> log/worker.log 2>&1
### 2.修改配置/代码之后，平滑重启
* php run.php reload >> log/worker.log 2>&1
### 3.停止
* php run.php stop
### 4.重启
* php run.php restart >> log/worker.log 2>&1
### 5.监控
* ps -ef|grep 'multi-process'




![监控图](monitor.png)


## 感谢

* [swoole](http://www.swoole.com/)

## 联系

qq群：141059677

