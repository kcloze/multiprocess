<?php

/*
 * This file is part of PHP CS Fixer.
 * (c) kcloze <pei.greet@qq.com>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Kcloze\MultiProcess;

use Exception;

class Process
{
    //shell脚本管理标示
    const PROCESS_NAME_LOG = ':kcloze-multi-process';
    //pid保存文件
    const PID_FILE = 'master.pid';
    const LOG_FILE = 'application.log';

    private $workers;
    private $workNum  = 5;
    private $config   = [];
    private $status   ='running';
    private $ppid     =0;

    public function __construct($config)
    {
        $this->config = $config;
        $this->logger = new Logs($config['logPath']);
    }

    public function start($command)
    {
        $this->checkMasterProcess($command);

        if (!isset($this->config['exec'])) {
            throw new Exception('config exec must be not null!');
        }
        $this->logger->log('process start pid: ' . $this->ppid);

        $this->setProcessName('php job master: ' . $this->ppid . self::PROCESS_NAME_LOG);
        foreach ($this->config['exec'] as $key => $value) {
            if (!isset($value['bin']) || !isset($value['binArgs'])) {
                throw new Exception('config bin/binArgs must be not null!');
            }

            $workOne['bin']    =$value['bin'];
            //子进程带上通用识别文字，方便ps查询进程
            $workOne['binArgs']=array_merge($value['binArgs'], [self::PROCESS_NAME_LOG]);
            //开启多个子进程
            for ($i = 0; $i < $value['workNum']; $i++) {
                $this->reserveExec($i, $workOne);
            }
        }

        $this->registSignal();
    }

    /**
     * 启动子进程，跑业务代码
     *
     * @param [type] $num
     * @param [type] $workOne
     */
    public function reserveExec($num, $workOne)
    {
        $reserveProcess = new \Swoole\Process(function ($worker) use ($num, $workOne) {
            //执行一个外部程序
            try {
                $this->logger->log('Worker exec: ' . $workOne['bin'] . ' ' . implode(' ', $workOne['binArgs']));
                //$worker->name('php job slave: ' . $workOne['bin'] . ' ' . implode(' ', [$workOne['binArgs'][0],$workOne['binArgs'][1]]));
                $worker->exec($workOne['bin'], $workOne['binArgs']);
            } catch (Exception $e) {
                $this->logger->log('error: ' . $workOne['binArgs'][0] . $e->getMessage());
            }
            //$this->logger->log('reserve process ' . $workOne['binArgs'][0] . ' is working ...');
        });
        $pid                 = $reserveProcess->start();
        $this->workers[$pid] = $reserveProcess;
        $this->logger->log('reserve start...' . $pid . PHP_EOL);
        echo 'reserve start...' . $pid . PHP_EOL;
    }

    //监控子进程
    public function registSignal()
    {
        //主进程收到退出信号，先把子进程结束，再结束自身
        \Swoole\Process::signal(SIGTERM, function ($signo) {
            $this->exit(true);
        });
        \Swoole\Process::signal(SIGUSR1, function ($signo) {
            $this->exit();
        });
        \Swoole\Process::signal(SIGCHLD, function ($signo) {
            while (true) {
                $ret = \Swoole\Process::wait(false);
                if ($ret) {
                    $pid           = $ret['pid'];
                    $childProcess = $this->workers[$pid];
                    $this->logger->log("Worker Exit, kill_signal={$ret['signal']} PID=" . $pid);
                    $this->logger->log('Worker count: ' . count($this->workers));
                    if ($this->status == 'running') {
                        $this->logger->log('Worker status: ' . $this->status);
                        $new_pid           = $childProcess->start();
                        $this->workers[$new_pid] = $childProcess;
                        $this->logger->log('Worker count: ' . count($this->workers));
                        unset($this->workers[$pid]);
                    }
                    $this->logger->log('Worker count: ' . count($this->workers));
                } else {
                    break;
                }
            }
        });
    }

    /**
     * 主进程退出后，执行流程.
     *
     * @param bool $killChild 是否强杀子进程
     */
    private function exit($killChild=false)
    {
        @unlink($this->config['logPath'] . '/' . self::PID_FILE);
        $this->logger->log('收到退出信号,[' . $this->ppid . ']主进程退出');
        $this->status = 'stop';
        $this->logger->log('Worker status: ' . $this->status);
        //杀掉子进程
        $this->logger->log('Kill Worker count: ' . count($this->workers));
        //是否强制杀子进程
        if (true === $killChild) {
            foreach ($this->workers as $pid => $worker) {
                //平滑退出，用exit；强制退出用kill
                \Swoole\Process::kill($pid);
                //$worker->exit(0);
                unset($this->workers[$pid]);
                $this->logger->log('主进程收到退出信号,[' . $pid . ']子进程跟着退出');
                $this->logger->log('Worker count: ' . count($this->workers));
            }
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

    private function checkMasterProcess($command)
    {
        // Get master process PID.
        $pidFile         =$this->config['logPath'] . '/' . self::PID_FILE;
        $masterPid       = @file_get_contents($pidFile);
        //服务没有启动
        if (!$masterPid && ($command === 'start' || $command === 'restart')) {
            //变成daemon pid会变
            \Swoole\Process::daemon(true, true);
            $this->ppid = getmypid();
            file_put_contents($this->config['logPath'] . '/' . self::PID_FILE, $this->ppid);

            return;
        }
        $this->ppid = getmypid();
        $masterIsAlive = $masterPid && @posix_kill($masterPid, 0);
        // Master is still alive?
        if ($masterIsAlive) {
            if ($command === 'start' && $this->ppid != $masterPid) {
                $logMsg="MultiProcess[$masterPid] already running";
                $this->logger->log($logMsg);
                exit($logMsg);
            }
        } elseif ($command !== 'start' && $command !== 'restart') {
            $logMsg="MultiProcess[$masterPid] not run";
            $this->logger->log($logMsg);
            exit($logMsg);
        }
    }
}
