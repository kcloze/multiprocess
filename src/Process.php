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

    public function start($config)
    {
        //如果swoole版本低于1.9.1需要修改默认参数
        \Swoole\Process::daemon();
        $this->config = $config;
        if (isset($config['workNum'])) {
            $this->workNum=$config['workNum'];
        }
        $ppid = getmypid();
        file_put_contents($this->config['logPath'] . '/' . self::PID_FILE, $ppid . "\n");
        $this->setProcessName('php job master ' . $ppid . self::PROCESS_NAME_LOG);

        //开启多个子进程
        for ($i = 0; $i < $this->workNum; $i++) {
            $this->reserveQueue($i);
        }
        $this->registSignal($this->workers);
    }

    public function reserveQueue($workOne)
    {
        $reserveProcess = new \Swoole\Process(function ($worker) use ($workOne) {
            //执行一个外部程序
            try {
                $this->log('Worker exec: ' . $this->config['bin'] . ' ' . implode(' ', $this->config['binArgs']));
                $worker->exec($this->config['bin'], $this->config['binArgs']);
            } catch (Exception $e) {
                $this->log('error: ' . $this->config['binArgs'][0] . $e->getMessage());
            }
            $this->log('reserve process ' . $workOne . ' is working ...');
        });
        $pid                 = $reserveProcess->start();
        $this->workers[$pid] = $reserveProcess;
        $this->log('reserve start...' . PHP_EOL);
        echo 'reserve start...' . PHP_EOL;
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
                    $new_pid           = $child_process->start();
                    $workers[$new_pid] = $child_process;
                    unset($workers[$pid]);
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
