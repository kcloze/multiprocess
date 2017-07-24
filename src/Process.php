<?php

/*
 * This file is part of PHP CS Fixer.
 * (c) kcloze <pei.greet@qq.com>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Kcloze\MultiProcess;

class Process
{
    //shell脚本管理标示
    const PROCESS_NAME_LOG = ': reserve process';
    //pid保存文件
    const PID_FILE = 'master.pid';
    const LOG_FILE = 'application.log';

    private $reserveProcess;
    private $workers;
    private $workNum = 5;
    private $config  = [];
    private $status  ='running';
    private $bin     ='';
    private $binArgs =[];

    public function start($config)
    {
        //如果swoole版本低于1.9.1需要修改默认参数
        \Swoole\Process::daemon();
        $this->config = $config;
        if (!isset($this->config['exec'])) {
            throw new Exception('config exec must be not null!');
        }

        $ppid = getmypid();
        $this->log('process start pid: ' . $ppid);
        //file_put_contents($this->config['logPath'] . '/' . self::PID_FILE, $ppid . "\n");
        $this->setProcessName('php job master ' . $ppid . self::PROCESS_NAME_LOG);
        foreach ($this->config['exec'] as $key => $value) {
            if (!isset($value['bin']) || !isset($value['binArgs'])) {
                throw new Exception('config bin/binArgs must be not null!');
            }

            $workOne['bin']    =$value['bin'];
            $workOne['binArgs']=$value['binArgs'];
            //开启多个子进程
            for ($i = 0; $i < $value['workNum']; $i++) {
                $this->reserveQueue($i, $workOne);
            }
        }

        $this->registSignal($this->workers);
    }

    public function reserveQueue($num, $workOne)
    {
        $reserveProcess = new \Swoole\Process(function ($worker) use ($num, $workOne) {
            //执行一个外部程序
            try {
                $this->log('Worker exec: ' . $workOne['bin'] . ' ' . implode(' ', $workOne['binArgs']));
                $worker->exec($workOne['bin'], $workOne['binArgs']);
            } catch (Exception $e) {
                $this->log('error: ' . $workOne['binArgs'][0] . $e->getMessage());
            }
            $this->log('reserve process ' . $workOne['binArgs'][0] . ' is working ...');
        });
        $pid                 = $reserveProcess->start();
        $this->workers[$pid] = $reserveProcess;
        $this->log('reserve start...' . $pid . PHP_EOL);
        echo 'reserve start...' . $pid . PHP_EOL;
    }

    //监控子进程
    public function registSignal($workers)
    {
        \Swoole\Process::signal(SIGTERM, function ($signo) {
            $this->exitMaster();
        });
        \Swoole\Process::signal(SIGCHLD, function ($signo) use (&$workers) {
            while (true) {
                $ret = \Swoole\Process::wait(false);
                if ($ret) {
                    $pid           = $ret['pid'];
                    $child_process = $workers[$pid];
                    $this->log("Worker Exit, kill_signal={$ret['signal']} PID=" . $pid);
                    if ($this->status == 'running') {
                        $new_pid           = $child_process->start();
                        $workers[$new_pid] = $child_process;
                        unset($workers[$pid]);
                    }
                } else {
                    break;
                }
            }
        });
    }

    private function exitMaster()
    {
        @unlink($this->config['logPath'] . '/' . self::PID_FILE);
        $this->log('收到退出信号,主进程退出');
        $this->status == 'stop';
        //杀掉子进程
        foreach ($this->workers as $pid => $worker) {
            \Swoole\Process::kill($pid);
            $this->log('主进程收到退出信号,[' . $pid . ']子进程跟着退出');
        }
        exit();
    }

    /**
     * 设置进程名.
     *
     * @param mixed $name
     */
    private function setProcessName($name)
    {
        //mac os不支持进程重命名
        if (function_exists('swoole_set_process_name') && PHP_OS != 'Darwin') {
            swoole_set_process_name($name);
        }
    }

    private function log($txt)
    {
        $txt='Time: ' . microtime(true) . PHP_EOL . $txt . PHP_EOL;
        file_put_contents($this->config['logPath'] . '/' . self::LOG_FILE, $txt, FILE_APPEND);
    }
}
