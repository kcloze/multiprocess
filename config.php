<?php

/*
 * This file is part of PHP CS Fixer.
 * (c) kcloze <pei.greet@qq.com>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

return [

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

];
