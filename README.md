# multiprocess [中文文档](README.zh.md)

* Based on swoole script management, for multi-process and daemon management
* Easy to make the common PHP script change daemon and multi-process execution
* The number of processes can be configured and multiple commands can be executed at once
* Automatic restart when the child process exits in an abnormal way
* When the main process exits in an abnormal way, the sub-process exits (smoothing out) after the work is done.



## Scenario

* PHP requires running one or more cli script consumption queues (resident)
* The implementation of the script automatically pulls up after the exit, preventing the consumption queue from working, affecting the business
* In fact, the supervisor can easily do something, this is just another implementation of PHP, no need to change the technology stack

## Installation
* git clone https://github.com/kcloze/multiprocess.git
* composer install
* modify config.php based on your business configuration


## Configure the instance
* Execute multiple commands at once
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
## Running

### 1.start
* chmod -R u+r log/
* php run.php start >> log/worker.log 2>&1
### 2.stop
* php run.php stop
### 3.restart
* php run.php restart
### 4.monitor
* ps -ef|grep 'multi-process'




![monitor img](monitor.png)


## Thanks

* [swoole](http://www.swoole.com/)

## Contact

QQ group：141059677

