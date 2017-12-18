<?php


define('APP_PATH', dirname(__DIR__));
date_default_timezone_set('Asia/Shanghai');

require APP_PATH . '/vendor/autoload.php';
$config = require_once APP_PATH . '/config.php';

var_dump($config);

use Kcloze\MultiProcess\Config;

$result=Config::hasRepeatingName($config['exec'], 'name');
var_dump($result);
