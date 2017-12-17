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
    'processName'  => ':swooleMultiProcess', // 设置进程名, 方便管理, 默认值 swooleTopicQueue
    'redis'        => [
        'host'=> '192.168.1.105',
        'port'=> '6379',
        //'password'=>'',
    ],

    //exec任务相关
    'exec'      => [
        [
            'name'      => 'kcloze-test-1',
            'bin'       => '/usr/local/bin/php',
            'binArgs'   => [__DIR__ . '/test/test.php', 'oop', '123'],
            'workNum'   => 3,
        ],
        [
            'name'      => 'kcloze-test-2',
            'bin'       => '/usr/local/bin/php',
            'binArgs'   => [__DIR__ . '/test/test2.php', 'oop', '456'],
            'workNum'   => 5,
        ],
        // [
        //     'name'      => 'kcloze-test-3',
        //     'bin'       => '/usr/bin/python',
        //     'binArgs'   => [__DIR__ . '/test/test3.py', 'oop', '369'],
        //     'workNum'   => 2,
        // ],
    ],

];
