<?php


return [

    'logPath'   => __DIR__ . '/log',
    'exec'      => [
        [
            'name'     => 'kcloze-test-1',
            'bin'       => '/usr/bin/php',
            'binArgs'   => [__DIR__ . '/test.php', 'oop', '123'],
            'workNum'   => 3,
        ],
        [
            'name'     => 'kcloze-test-2',
            'bin'       => '/usr/bin/php',
            'binArgs'   => [__DIR__ . '/test2.php', 'oop', '456'],
            'workNum'   => 5,
        ],
    ],

];