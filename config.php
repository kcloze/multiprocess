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
    'sleepTime'    => 3000, // 子进程退出之后，自动拉起暂停毫秒数
    'redis'        => [
        'host'  => '127.0.0.1',
        'port'  => '6379',
        'preKey'=> 'SwooleMultiProcess-',
        //'password'=>'',
    ],

    //exec任务相关,name的名字不能相同
    'exec'      => [
        [
            'name'      => 'kcloze-test-1',
            'bin'       => '/usr/bin/php7.1',
            'binArgs'   => [__DIR__ . '/test/cli/test.php', 'oop', '123'],
            'workNum'   => 3,
        ],
        [
            'name'      => 'kcloze-test-2',
            'bin'       => '/usr/bin/php7.1',
            'binArgs'   => [__DIR__ . '/test/cli/test.php', 'oop', '123'],
            'workNum'   => 2,
        ],
        [
            'name'      => 'kcloze-test-3',
            'bin'       => '/usr/bin/php7.1',
            'binArgs'   => [__DIR__ . '/test/cli/test2.php', 'oop', '456'],
            'workNum'   => 5,
        ],
        // [
        //     'name'      => 'kcloze-test-3',
        //     'bin'       => '/usr/bin/python',
        //     'binArgs'   => [__DIR__ . '/test/cli/test3.py', 'oop', '369'],
        //     'workNum'   => 2,
        // ],
    ],

];
