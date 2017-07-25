<?php

/*
 * This file is part of PHP CS Fixer.
 * (c) kcloze <pei.greet@qq.com>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

class Process
{
    public $mpid       =0;
    public $works      =[];
    public $max_precess=5;
    public $new_index  =0;

    public function __construct()
    {
        try {
            $this->setProcessName(sprintf('php-ps:%s', 'master'));
            $this->mpid = posix_getpid();
            $this->run();
            $this->processWait();
        } catch (\Exception $e) {
            die('ALL ERROR: ' . $e->getMessage());
        }
    }

    public function run()
    {
        for ($i=0; $i < $this->max_precess; $i++) {
            $this->CreateProcess();
        }
    }

    public function CreateProcess($index=null)
    {
        $process = new swoole_process(function (swoole_process $worker) use ($index) {
            if (null === $index) {
                $index=$this->new_index;
                $this->new_index++;
            }
            $this->setProcessName(sprintf('php-ps:%s', $index));
            for ($j = 0; $j < 16000; $j++) {
                $this->checkMpid($worker);
                echo "msg: {$index}\n";
                sleep(1);
            }
        }, false, false);
        $pid                =$process->start();
        $this->works[$index]=$pid;

        return $pid;
    }

    public function checkMpid(&$worker)
    {
        if (!swoole_process::kill($this->mpid, 0)) {
            $worker->exit();
            // 这句提示,实际是看不到的.需要写到日志中
            echo "Master process exited, I [{$worker['pid']}] also quit\n";
        }
    }

    public function rebootProcess($ret)
    {
        $pid  =$ret['pid'];
        $index=array_search($pid, $this->works, true);
        if ($index !== false) {
            $index  =intval($index);
            $new_pid=$this->CreateProcess($index);
            echo "rebootProcess: {$index}={$new_pid} Done\n";

            return;
        }
        throw new \Exception('rebootProcess Error: no pid');
    }

    public function processWait()
    {
        while (1) {
            if (count($this->works)) {
                var_dump('count: ', count($this->works));
                $ret = swoole_process::wait();
                if ($ret) {
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

new Process();
