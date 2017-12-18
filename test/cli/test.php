<?php

/*
 * This file is part of PHP CS Fixer.
 * (c) kcloze <pei.greet@qq.com>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

ini_set('date.timezone', 'Asia/Shanghai');

echo 'test1 time: ' . date('Y-m-d H:i:s');

sleep(15);

$i= mt_rand(1, 5);
var_dump($i);
// if ($i == 3) {
//     NotExit();
// }
