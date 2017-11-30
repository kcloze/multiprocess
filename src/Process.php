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
    public $processName    = ':swooleMultiProcess'; // 进程重命名, 方便 shell 脚本管理
    private $workers;
    private $ppid;
    private $workNum  = 5;
    private $config   = [];
    private $pidFile  = '';
    private $status   ='running'; //主进程状态

    public function __construct()
    {
        $this->config  =  Config::getConfig();
        $this->logger  = Logs::getLogger($this->config['logPath'] ?? []);

        if (isset($this->config['pidPath']) && !empty($this->config['pidPath'])) {
            $this->pidFile=$this->config['pidPath'] . '/master.pid';
        } else {
            $this->pidFile=APP_PATH . '/master.pid';
        }
        if (isset($this->config['processName']) && !empty($this->config['processName'])) {
            $this->processName = $this->config['processName'];
        }

        /*
         * master.pid 文件记录 master 进程 pid, 方便之后进程管理
         * 请管理好此文件位置, 使用 systemd 管理进程时会用到此文件
         */
        if (file_exists($this->pidFile)) {
            echo '已有进程运行中,请先结束或重启';
            die();
        }
        \Swoole\Process::daemon();
        $this->ppid = getmypid();
        file_put_contents($this->pidFile, $this->ppid);
        $this->setProcessName('multiprocess master ' . $this->ppid . $this->processName);
    }

    public function start()
    {
        if (!isset($this->config['exec'])) {
            die('config exec must be not null!');
        }
        $this->logger->log('process start pid: ' . $this->ppid, 'info', Logs::LOG_SAVE_FILE_WORKER);

        foreach ($this->config['exec'] as $key => $value) {
            if (!isset($value['bin']) || !isset($value['binArgs'])) {
                $this->logger->log('config bin/binArgs must be not null!', 'error', Logs::LOG_SAVE_FILE_WORKER);
            }

            $workOne['bin']    =$value['bin'];
            //子进程带上通用识别文字，方便ps查询进程
            $workOne['binArgs']=array_merge($value['binArgs'], [$this->processName]);
            //开启多个子进程
            for ($i = 0; $i < $value['workNum']; $i++) {
                $this->reserveExec($i, $workOne);
            }
        }

        $this->registSignal($this->workers);
    }

    /**
     * 启动子进程，跑业务代码
     *
     * @param [type] $num
     * @param [type] $workOne
     * @param mixed  $workNum
     */
    public function reserveExec($workNum, $workOne)
    {
        $reserveProcess = new \Swoole\Process(function ($worker) use ($workNum, $workOne) {
            try {
                $this->logger->log('Worker exec: ' . $workOne['bin'] . ' ' . implode(' ', $workOne['binArgs']), 'info', Logs::LOG_SAVE_FILE_WORKER);
                //执行一个外部程序
                $worker->exec($workOne['bin'], $workOne['binArgs']);
            } catch (Exception $e) {
                $this->logger->log('error: ' . $workOne['binArgs'][0] . $e->getMessage(), 'error', Logs::LOG_SAVE_FILE_WORKER);
            }
            $this->logger->log('worker id: ' . $workNum . ' is done!!!', 'info', Logs::LOG_SAVE_FILE_WORKER);
        });
        $pid                 = $reserveProcess->start();
        $this->workers[$pid] = $reserveProcess;
        $this->logger->log('worker id: ' . $workNum . ' pid: ' . $pid . ' is start...', 'info', Logs::LOG_SAVE_FILE_WORKER);
    }

    //注册信号
    public function registSignal(&$workers)
    {
        \Swoole\Process::signal(SIGTERM, function ($signo) {
            $this->killWorkersAndExitMaster();
        });
        \Swoole\Process::signal(SIGUSR1, function ($signo) {
            $this->waitWorkers();
        });
        \Swoole\Process::signal(SIGCHLD, function ($signo) use (&$workers) {
            while (true) {
                $ret = \Swoole\Process::wait(false);
                if ($ret) {
                    $pid           = $ret['pid'];
                    $child_process = $workers[$pid];
                    //主进程状态为running才需要拉起子进程
                    if ($this->status == 'running') {
                        $new_pid           = $child_process->start();
                        $this->logger->log("Worker Restart, kill_signal={$ret['signal']} PID=" . $new_pid, 'info', Logs::LOG_SAVE_FILE_WORKER);
                        $workers[$new_pid] = $child_process;
                    }
                    $this->logger->log("Worker Exit, kill_signal={$ret['signal']} PID=" . $pid, 'info', Logs::LOG_SAVE_FILE_WORKER);
                    unset($workers[$pid]);
                    $this->logger->log('Worker count: ' . count($workers), 'info', Logs::LOG_SAVE_FILE_WORKER);
                    //如果$workers为空，且主进程状态为wait，说明所有子进程安全退出，这个时候主进程退出
                    if (empty($workers) && $this->status == 'wait') {
                        $this->logger->log('主进程收到所有信号子进程的退出信号，子进程安全退出完成', 'info', Logs::LOG_SAVE_FILE_WORKER);
                        $this->exitMaster();
                    }
                } else {
                    break;
                }
            }
        });
    }

    //平滑等待子进程退出之后，再退出主进程
    private function waitWorkers()
    {
        $this->status   ='wait';
    }

    //强制杀死子进程并退出主进程
    private function killWorkersAndExitMaster()
    {
        //修改主进程状态为stop
        $this->status   ='stop';
        foreach ($this->workers as $pid => $worker) {
            //强制杀workers子进程
            \Swoole\Process::kill($pid);
            unset($this->workers[$pid]);
            $this->logger->log('主进程收到退出信号,[' . $pid . ']子进程跟着退出', 'info', Logs::LOG_SAVE_FILE_WORKER);
            $this->logger->log('Worker count: ' . count($this->workers), 'info', Logs::LOG_SAVE_FILE_WORKER);
        }
        $this->exitMaster();
    }

    //退出主进程
    private function exitMaster()
    {
        @unlink($this->pidFile);
        $this->logger->log('Time: ' . microtime(true) . '主进程' . $this->ppid . '退出', 'info', Logs::LOG_SAVE_FILE_WORKER);
        sleep(1);
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
}
