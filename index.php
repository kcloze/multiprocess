<?php

/*
 * This file is part of PHP CS Fixer.
 * (c) kcloze <pei.greet@qq.com>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

date_default_timezone_set('Asia/Shanghai');

require __DIR__ . '/vendor/autoload.php';

$config = [

    'logPath'   => __DIR__ . '/log',
    'bin'       => '/usr/bin/php',
    'binArgs'   => [__DIR__ . '/test.php', 'oop', '123'],
    'workNum'   => 3,

];

//启动
try {
    $process = new Kcloze\MultiProcess\Process();
    $process->start($config);
} catch (\Exception $e) {
    die('ALL ERROR: ' . $e->getMessage());
}
