<?php

/*
 * This file is part of PHP CS Fixer.
 * (c) pei.greet <pei.greet@qq.com>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Kcloze\MultiProcess;

use Exception;

class Process
{
    //shell脚本管理标示
    const PROCESS_NAME_LOG = ': reserve process';
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

    public function start()
    {
        //如果swoole版本低于1.9.1需要修改默认参数
        \Swoole\Process::daemon();

        if (!isset($this->config['exec'])) {
            throw new Exception('config exec must be not null!');
        }

        $this->ppid = getmypid();
        $this->logger->log('process start pid: ' . $this->ppid);
        file_put_contents($this->config['logPath'] . '/' . self::PID_FILE, $this->ppid);
        $this->setProcessName('php job master: ' . $this->ppid . self::PROCESS_NAME_LOG);
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

        $this->registSignal();
    }

    public function reserveQueue($num, $workOne)
    {
        $reserveProcess = new \Swoole\Process(function ($worker) use ($num, $workOne) {
            //执行一个外部程序
            try {
                $this->logger->log('Worker exec: ' . $workOne['bin'] . ' ' . implode(' ', $workOne['binArgs']));
                $worker->exec($workOne['bin'], $workOne['binArgs']);
            } catch (Exception $e) {
                $this->logger->log('error: ' . $workOne['binArgs'][0] . $e->getMessage());
            }
            $this->setProcessName('php job slave: ' . $workOne['bin'] . ' ' . implode(' ', $workOne['binArgs']));
            $this->logger->log('reserve process ' . $workOne['binArgs'][0] . ' is working ...');
        });
        $pid                 = $reserveProcess->start();
        $reserveProcess->name('php server.php: worker');
        $this->workers[$pid] = $reserveProcess;
        $this->logger->log('reserve start...' . $pid . PHP_EOL);
        echo 'reserve start...' . $pid . PHP_EOL;
    }

    //监控子进程
    public function registSignal()
    {
        //主进程收到退出信号，先把子进程结束，再结束自身
        \Swoole\Process::signal(SIGTERM, function ($signo) {
            $this->exit();
        });
        \Swoole\Process::signal(SIGCHLD, function ($signo) {
            while (true) {
                $ret = \Swoole\Process::wait(false);
                if ($ret) {
                    $pid           = $ret['pid'];
                    $child_process = $this->workers[$pid];
                    $this->logger->log("Worker Exit, kill_signal={$ret['signal']} PID=" . $pid);
                    $this->logger->log('Worker count: ' . count($this->workers));
                    if ($this->status == 'running') {
                        $this->logger->log('Worker status: ' . $this->status);
                        $new_pid           = $child_process->start();
                        $this->workers[$new_pid] = $child_process;
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

    private function exit()
    {
        @unlink($this->config['logPath'] . '/' . self::PID_FILE);
        $this->logger->log('收到退出信号,[' . $this->ppid . ']主进程退出');
        $this->status = 'stop';
        $this->logger->log('Worker status: ' . $this->status);
        //杀掉子进程
        $this->logger->log('Worker count: ' . count($this->workers));
        foreach ($this->workers as $pid => $worker) {
            //\Swoole\Process::kill($pid);
            //平滑退出，用exit；强制退出用kill
            $worker->exit(0);
            unset($this->workers[$pid]);
            $this->logger->log('主进程收到退出信号,[' . $pid . ']子进程跟着退出');
            $this->logger->log('Worker count: ' . count($this->workers));
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
