<?php

/*
 * This file is part of PHP CS Fixer.
 * (c) kcloze <pei.greet@qq.com>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Kcloze\MultiProcess;

class Config
{
    private static $config=[];

    public static function setConfig($config)
    {
        self::$config=$config;
    }

    public static function getConfig()
    {
        return self::$config;
    }

    public static function hasRepeatingName($config=[], $chckKey='name')
    {
        $nameList=[];
        foreach ($config as $key => $value) {
            if (isset($nameList[$value[$chckKey]])) {
                return true;
            }
            $nameList[$value[$chckKey]]=$value[$chckKey];
        }

        return false;
    }
}
