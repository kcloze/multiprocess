# multiprocess
* 基于swoole的常驻PHP cli脚本管理
* 进程个数可配置，worker进程退出后会自动拉起

## 场景
* PHP需要跑一个或多个cli脚本消费队列（常驻）
* 实现脚本退出后自动拉起，防止消费队列不工作，影响业务
* 其实supervisor可以轻松做个事情，这个只是PHP的另一种实现，不需要换技术栈

## 安装
* git clone https://github.com/kcloze/multiprocess.git
* composer install
* 根据自己业务配置,修改config.php
```
    'bin'       => '/usr/bin/php',
    'binArgs'   => [__DIR__ . '/test.php', 'oop', '123'],
```

## 配置实例

```
    'logPath'   => __DIR__ . '/log',
    'exec'      => [
        [
            'token'     => 'kcloze-test-1',
            'bin'       => '/usr/bin/php',
            'binArgs'   => [__DIR__ . '/test.php', 'oop', '123'],
            'workNum'   => 3,
        ],
        [
            'token'     => 'kcloze-test-2',
            'bin'       => '/usr/bin/php',
            'binArgs'   => [__DIR__ . '/test2.php', 'oop', '456'],
            'workNum'   => 5,
        ],
    ],

```
## 运行
* chmod -R u+r log/
* php run.php start >> log/worker.log 2>&1

![监控图](monitor.png)


## 感谢

* [swoole](http://www.swoole.com/)

## 联系

qq群：141059677

