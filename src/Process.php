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
    const STATUS_START              ='start'; //主进程启动中状态
    const STATUS_RUNNING            ='runnning'; //主进程正常running状态
    const STATUS_WAIT               ='wait'; //主进程wait状态
    const STATUS_STOP               ='stop'; //主进程stop状态
    const STATUS_RECOVER            ='recover'; //主进程recover状态

    public $processName    = ':swooleMultiProcess'; // 进程重命名, 方便 shell 脚本管理
    private $workers;
    private $workersByNamePids;
    private $workersByPidName;
    private $ppid;
    private $configWorkersByNameNum;
    private $checkTickTimer      = 5000; //检查服务是否正常定时器,单位ms
    private $config              = [];
    private $pidFile             = 'master.pid';
    private $pidInfoFile         = 'master.info';
    private $status              =''; //主进程状态
    private $timer               =''; //定时器id
    private $redis               =null; //redis连接

    public function __construct()
    {
        $this->config  =  Config::getConfig();

        if (Config::hasRepeatingName($this->config['exec'], 'name')) {
            die('exec name has repeating name,fetal error!');
        }

        $this->logger  = Logs::getLogger($this->config['logPath'] ?? []);

        if (isset($this->config['pidPath']) && !empty($this->config['pidPath'])) {
            Utils::mkdir($this->config['pidPath']);
            $this->pidFile    =$this->config['pidPath'] . '/' . $this->pidFile;
            $this->pidInfoFile=$this->config['pidPath'] . '/' . $this->pidInfoFile;
        } else {
            die('config pidPath must be set!');
        }
        if (isset($this->config['processName']) && !empty($this->config['processName'])) {
            $this->processName = $this->config['processName'];
        }

        /*
         * master.pid 文件记录 master 进程 pid, 方便之后进程管理
         * 请管理好此文件位置, 使用 systemd 管理进程时会用到此文件
         * 判断文件是否存在，并判断进程是否在运行
         */
        if (file_exists($this->pidFile)) {
            $pid=$this->getMasterPid();
            if ($pid && @\Swoole\Process::kill($pid, 0)) {
                die('已有进程运行中,请先结束或重启' . PHP_EOL);
            }
        }

        \Swoole\Process::daemon();
        $this->ppid    = getmypid();
        $this->saveMasterPid();
        $this->setProcessName('multiprocess master ' . $this->ppid . $this->processName);
    }

    public function start()
    {
        $data                      =[];
        $data['status']            =self::STATUS_START;
        $this->saveMasterData($data);
        if (!isset($this->config['exec'])) {
            die('config exec must be not null!');
        }
        $this->logger->log('process start pid: ' . $this->ppid, 'info', Logs::LOG_SAVE_FILE_WORKER);

        $this->configWorkersByNameNum=[];
        foreach ($this->config['exec'] as $key => $value) {
            if (!isset($value['bin']) || !isset($value['binArgs'])) {
                $this->logger->log('config bin/binArgs must be not null!', 'error', Logs::LOG_SAVE_FILE_WORKER);
            }

            $workOne['bin']     =$value['bin'];
            $workOne['name']    =$value['name'];
            //子进程带上通用识别文字，方便ps查询进程
            $workOne['binArgs']=array_merge($value['binArgs'], [$this->processName]);
            //开启多个子进程
            for ($i = 0; $i < $value['workNum']; $i++) {
                $this->reserveExec($i, $workOne);
            }
            $this->configWorkersByNameNum[$value['name']] = $value['workNum'];
        }

        if (empty($this->timer)) {
            $this->registSignal();
            $this->registTimer();
        }//启动成功，修改状态
        $data                      =[];
        $data['status']            =self::STATUS_RUNNING;
        $this->saveMasterData($data);
    }

    public function startByWorkerName($workName)
    {
        $data                      =[];
        $data[$workName . 'Status']=self::STATUS_START;
        $this->saveMasterData($data);
        foreach ($this->config['exec'] as $key => $value) {
            if ($value['name'] != $workName) {
                continue;
            }
            if (!isset($value['bin']) || !isset($value['binArgs'])) {
                $this->logger->log('config bin/binArgs must be not null!', 'error', Logs::LOG_SAVE_FILE_WORKER);
            }

            $workOne['bin']     =$value['bin'];
            $workOne['name']    =$value['name'];
            //子进程带上通用识别文字，方便ps查询进程
            $workOne['binArgs']=array_merge($value['binArgs'], [$this->processName]);
            //开启多个子进程
            for ($i = 0; $i < $value['workNum']; $i++) {
                $this->reserveExec($i, $workOne);
            }
        }
        $data                      =[];
        $data[$workName . 'Status']=self::STATUS_RUNNING;
        $this->saveMasterData($data);
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
            $this->checkMpid($worker);
            try {
                $this->logger->log('Worker exec: ' . $workOne['bin'] . ' ' . implode(' ', $workOne['binArgs']), 'info', Logs::LOG_SAVE_FILE_WORKER);
                //执行一个外部程序
                $worker->exec($workOne['bin'], $workOne['binArgs']);
            } catch (\Throwable $e) {
                Utils::catchError($this->logger, $e);
            } catch (\Exception $e) {
                Utils::catchError($this->logger, $e);
            }
            $this->logger->log('worker id: ' . $workNum . ' is done!!!', 'info', Logs::LOG_SAVE_FILE_WORKER);
            $worker->exit(0);
        });
        $pid                                        = $reserveProcess->start();
        $this->workers[$pid]                        = $reserveProcess;
        $this->workersByNamePids[$workOne['name']]  =$this->workersByNamePids[$workOne['name']] ?? 0;
        $this->workersByNamePids[$workOne['name']]++;
        $this->workersByPidName[$pid]               =$workOne['name'];
        $data                                       =[];
        $data[$workOne['name'] . 'Status']          =self::STATUS_RUNNING;
        $this->saveMasterData($data);
        $this->logger->log('worker id: ' . $workNum . ' pid: ' . $pid . ' is start...', 'info', Logs::LOG_SAVE_FILE_WORKER);
    }

    //注册信号
    public function registSignal()
    {
        \Swoole\Process::signal(SIGTERM, function ($signo) {
            $this->killWorkersAndExitMaster();
        });
        \Swoole\Process::signal(SIGKILL, function ($signo) {
            $this->killWorkersAndExitMaster();
        });
        \Swoole\Process::signal(SIGUSR1, function ($signo) {
            $this->waitWorkers();
        });
        \Swoole\Process::signal(SIGCHLD, function ($signo) {
            while (true) {
                $ret = \Swoole\Process::wait(false);
                if ($ret) {
                    $pid           = $ret['pid'];
                    $childProcess = $this->workers[$pid];
                    $workName=$this->workersByPidName[$pid];
                    $this->status=$this->getMasterData('status');
                    //根据wokerName，获取其运行状态
                    $workNameStatus=$this->getMasterData($workName . 'Status');
                    //主进程状态为start,running且子进程组不是recover状态才需要拉起子进程
                    if ($workNameStatus != Process::STATUS_RECOVER && ($this->status == Process::STATUS_RUNNING || $this->status == Process::STATUS_START)) {
                        try {
                            $newPid  = $childProcess->start();
                        } catch (\Throwable $e) {
                            Utils::catchError($this->logger, $e, 'error: woker restart fail...');
                        } catch (\Exception $e) {
                            Utils::catchError($this->logger, $e, 'error: woker restart fail...');
                        }
                        $this->logger->log("Worker Restart, kill_signal={$ret['signal']} PID=" . $newPid, 'info', Logs::LOG_SAVE_FILE_WORKER);
                        $this->workers[$newPid] = $childProcess;
                        $this->workersByNamePids[$workName]++;
                        $this->workersByPidName[$newPid]        =$workName;
                    }
                    $this->logger->log("Worker Exit, kill_signal={$ret['signal']} PID=" . $pid, 'info', Logs::LOG_SAVE_FILE_WORKER);
                    unset($this->workers[$pid], $this->workersByNamePids[$workName][$pid], $this->workersByPidName[$pid]);
                    $this->workersByNamePids[$workName]--;
                    //根据配置workername进程数跟实际wokers是否相等,不相等说明有异常，需要重启recover所有子进程
                    if ($workNameStatus == Process::STATUS_RUNNING && $this->configWorkersByNameNum[$workName] != $this->workersByNamePids[$workName]) {
                        $data                      =[];
                        $data[$workName . 'Status']=Process::STATUS_RECOVER;
                        $this->saveMasterData($data);
                        $this->logger->log('Worker config nums: ' . $this->configWorkersByNameNum[$workName] . '!=' . $this->workersByNamePids[$workName], 'error', Logs::LOG_SAVE_FILE_WORKER);
                    }
                    $this->logger->log('Worker count: ' . count($this->workers) . '  [' . $workName . ']  ' . $this->configWorkersByNameNum[$workName] . '==' . $this->workersByNamePids[$workName], 'info', Logs::LOG_SAVE_FILE_WORKER);
                    //如果$this->workers为空，且主进程状态为wait，说明所有子进程安全退出，这个时候主进程退出
                    if (empty($this->workers) && $this->status == Process::STATUS_WAIT) {
                        $this->logger->log('主进程收到所有信号子进程的退出信号，子进程安全退出完成', 'info', Logs::LOG_SAVE_FILE_WORKER);
                        $this->exitMaster();
                    }
                } else {
                    break;
                }
            }
        });
    }

    public function registTimer()
    {
        $this->timer=\Swoole\Timer::tick($this->checkTickTimer, function ($timerId) {
            foreach ($this->configWorkersByNameNum as $key => $value) {
                $workName=$key;
                $this->status  =$this->getMasterData('status');
                $workNameStatus=$this->getMasterData($workName . 'Status');

                if ($this->workersByNamePids[$workName] <= 0 && $workNameStatus == Process::STATUS_RECOVER) {
                    $data                      =[];
                    $data[$workName . 'Status']=Process::STATUS_START;
                    $this->saveMasterData($data);
                    $this->startByWorkerName($workName);
                    $this->logger->log('主进程 recover 子进程：' . $workName, 'info', Logs::LOG_SAVE_FILE_WORKER);
                }
                $this->logger->log('主进程状态：' . $this->status, 'info', Logs::LOG_SAVE_FILE_WORKER);
                $this->logger->log('[' . $workName . ']子进程状态：' . $workNameStatus . ' 数量：' . $this->workersByNamePids[$workName], 'info', Logs::LOG_SAVE_FILE_WORKER);
            }
        });
    }

    //平滑等待子进程退出之后，再退出主进程
    private function killWorkersAndExitMaster()
    {
        //修改主进程状态为stop
        $this->status              =self::STATUS_STOP;
        $data                      =[];
        $data['status']            =self::STATUS_STOP;
        $this->saveMasterData($data);

        if ($this->workers) {
            foreach ($this->workers as $pid => $worker) {
                //强制杀workers子进程
            if (\Swoole\Process::kill($pid) == true) {
                unset($this->workers[$pid]);
                $this->logger->log('子进程[' . $pid . ']收到强制退出信号,退出成功', 'info', Logs::LOG_SAVE_FILE_WORKER);
            } else {
                $this->logger->log('子进程[' . $pid . ']收到强制退出信号,但退出失败', 'info', Logs::LOG_SAVE_FILE_WORKER);
            }

                $this->logger->log('Worker count: ' . count($this->workers), 'info', Logs::LOG_SAVE_FILE_WORKER);
            }
        }
        $this->exitMaster();
    }

    //强制杀死子进程并退出主进程
    private function waitWorkers()
    {
        //修改主进程状态为wait
        $data                      =[];
        $data['status']            =self::STATUS_WAIT;
        $this->saveMasterData($data);
        $this->status = self::STATUS_WAIT;
        foreach ($this->configWorkersByNameNum as $key => $value) {
            $workName                  =$key;
            $data                      =[];
            $data[$workName . 'Status']=self::STATUS_WAIT;
            $this->saveMasterData($data);
        }
    }

    //退出主进程
    private function exitMaster()
    {
        @unlink($this->pidFile);
        $this->clearMasterData();
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

    //主进程如果不存在了，子进程退出
    private function checkMpid(&$worker)
    {
        if (!@\Swoole\Process::kill($this->ppid, 0)) {
            $worker->exit();
            $this->logger->log("Master process exited, I [{$worker['pid']}] also quit");
        }
    }

    private function saveMasterPid()
    {
        file_put_contents($this->pidFile, $this->ppid);
    }

    private function getMasterPid()
    {
        file_get_contents($this->pidFile);
    }

    private function saveMasterData($data=[])
    {
        $this->redis   = $this->getRedis();
        foreach ((array) $data as $key => $value) {
            $key && $this->redis->set($key, $value);
        }
    }

    private function clearMasterData()
    {
        $this->redis = $this->getRedis();

        $data=$this->configWorkersByNameNum;
        foreach ((array) $data as $key => $value) {
            $value && $this->redis->del($key . 'Status');
            $this->logger->log('主进程退出前删除woker redis key： ' . $key . 'Status', 'info', Logs::LOG_SAVE_FILE_WORKER);
        }
        $this->redis->del('status');

        $this->logger->log('主进程退出前删除master redis key： status', 'info', Logs::LOG_SAVE_FILE_WORKER);
    }

    private function getMasterData($key)
    {
        $this->redis = $this->getRedis();
        if ($key) {
            return $this->redis->get($key);
        }
    }

    private function getRedis()
    {
        if ($this->redis && $this->redis->ping()) {
            return $this->redis;
        }
        $this->redis   = new XRedis($this->config['redis']);

        return $this->redis;
    }
}
