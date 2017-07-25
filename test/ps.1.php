<?php

/*
 * This file is part of PHP CS Fixer.
 * (c) kcloze <pei.greet@qq.com>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

class MainProcess
{
    public $mpid                = 0;
    public $works               = [];
    public $max_process         = 5;
    public static $swoole_table = null;

    public function __construct()
    {
        try {
            self::$swoole_table = new swoole_table(1024);
            self::$swoole_table->column('index', swoole_table::TYPE_INT); //用于父子进程间数据交换
            self::$swoole_table->create();
            $this->setProcessName('php-ps:master');
            $this->mpid = posix_getpid();
            $this->run();
            $this->processWait();
        } catch (\Exception $e) {
            die('ALL ERROR:' . $e->getMessage());
        }
    }

    public function run()
    {
        for ($i=0; $i < $this->max_process; $i++) {
            $this->createProcess();
        }
    }

    public function createProcess($index=null)
    {
        $process = new swoole_process(function (swoole_process $worker) use ($index) {
            if (null === $index) {//如果没有指定了索引，新建的子进程，开启计数
                $indexValue=self::$swoole_table->get('index');
                if ($indexValue === false) {
                    $index = 0;
                } else {
                    $index++;
                }
            }
            self::$swoole_table->set('index', ['index'=>$index]);
            $this->setProcessName("php-ps:{$index}");
            for ($j=0; $j < 16000; $j++) {
                $this->checkMpid($worker);
                echo "msg:{$j}\n";
                sleep(1);
            }
        }, false, false);
        $pid                 = $process->start();
        $index               =self::$swoole_table->get('index');
        $index               =$index['index'];
        $this->works[$index] = $pid;

        return $pid;
    }

    public function checkMpid(&$worker)
    {
        if (!swoole_process::kill($this->mpid, 0)) { //import! check whether master process is running
            $worker->exit();
            file_put_contents('/tmp/runtime.log', "Master process exited, I [{$worker['pid']}] also quit\n", FILE_APPEND);
        }
    }

    public function rebootProcess($res)
    {
        $pid   = $ret['pid'];
        $index = array_search($pid, $this->works, true);
        if ($index !== false) {
            $index   = intval($index);
            $new_pid = $this->createProcess($index);
            echo "rebootProcess:{$index}={$new_pid} Done\n";

            return;
        }

        throw new \Exception('rebootProcess Error: no pid');
    }

    public function processWait()
    {
        while (1) {
            if (count($this->works)) {
                $ret = swoole_process::wait();
                if (isset($ret['pid'])) {
                    $this->rebootProcess($ret);
                }
            } else {
                break;
            }
        }
    }

    private function setProcessName($name)
    {
        //mac os不支持进程重命名
        if (function_exists('swoole_set_process_name') && PHP_OS != 'Darwin') {
            swoole_set_process_name($name);
        }
    }
}
new MainProcess();
