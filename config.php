<?php

/*
 * This file is part of PHP CS Fixer.
 * (c) kcloze <pei.greet@qq.com>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

return $config = [
    //log目录
    'logPath'      => __DIR__ . '/log',
    'pidPath'      => __DIR__ . '/log',
    'workerNum'    => 5, // 工作进程数, 默认值 5
    'usleep'       => 10000, //每次topic消费完之后停留毫秒数，线上环境不能过大
    'processName'  => ':swooleTopicQueue', // 设置进程名, 方便管理, 默认值 swooleTopicQueue
    //job任务相关
    'job'         => [
        'topics'  => [
            //key值越大，优先消费
            23=> 'MyJob', 86=>'MyJob2', 8=>'MyJob3',
        ],
        'queue'   => [
            'type' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
        ],
   ],
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

];
