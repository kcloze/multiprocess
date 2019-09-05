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
    const CHILD_PROCESS_CAN_RESTART        ='staticWorker'; //子进程可以重启,进程个数固定
    const CHILD_PROCESS_CAN_NOT_RESTART    ='dynamicWorker'; //子进程不可以重启，进程个数根据队列堵塞情况动态分配

    const STATUS_START                     ='start'; //主进程启动中状态
    const STATUS_RUNNING                   ='runnning'; //主进程正常running状态
    const STATUS_WAIT                      ='wait'; //主进程wait状态
    const STATUS_STOP                      ='stop'; //主进程stop状态
    const STATUS_RECOVER                   ='recover'; //主进程recover状态
    const REDIS_MASTER_KEY                 ='Status'; //Redis主进程状态key
    const REDIS_WORKER_STATUS_KEY          ='Status-'; //Redis 子进程状态key
    const REDIS_WORKER_MEMBER_KEY          ='Members-'; //主进程recover状态

    public $processName    = ':swooleMultiProcess'; // 进程重命名, 方便 shell 脚本管理
    private $workers;
    private $workersByPidName;
    private $ppid;
    private $configWorkersByNameNum;
    private $checkTickTimer       = 5000; //检查服务是否正常定时器,单位ms
    private $sleepTime            = 2000; //子进程退出之后，自动拉起暂停毫秒数
    private $config               = [];
    private $pidFile              = 'master.pid';
    private $status               =''; //主进程状态
    private $timer                =''; //定时器id
    private $redis                =null; //redis连接
    private $logSaveFileWorker    = 'workers.log';

    private $queueMaxNum          = 1000; //队列达到一定长度，增加子进程个数
    private $workersInfoList      = []; // 子进程队列
    private $dynamicWorkerNum     = []; //动态（不能重启）子进程计数，最大数为每个脚本配置dynamicWorkNum，它的个数是动态变化的

    public function __construct()
    {
        $this->config  =  Config::getConfig();

        if (Config::hasRepeatingName($this->config['exec'], 'name')) {
            die('exec name has repeating name,fetal error!');
        }
        $this->logger = new Logs(Config::getConfig()['logPath'] ?? '', $this->config['logSaveFileApp'] ?? '');

        if (isset($this->config['pidPath']) && !empty($this->config['pidPath'])) {
            Utils::mkdir($this->config['pidPath']);
            $this->pidFile    =$this->config['pidPath'] . '/' . $this->pidFile;
        } else {
            die('config pidPath must be set!');
        }
        if (isset($this->config['processName']) && !empty($this->config['processName'])) {
            $this->processName = $this->config['processName'];
        }
        if (isset($this->config['sleepTime']) && !empty($this->config['sleepTime'])) {
            $this->sleepTime = $this->config['sleepTime'];
        }
        if (isset($this->config['logSaveFileWorker']) && !empty($this->config['logSaveFileWorker'])) {
            $this->logSaveFileWorker = $this->config['logSaveFileWorker'];
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

    /**
     * 启动主进程.
     */
    public function start()
    {
        $this->saveMasterData([self::REDIS_MASTER_KEY =>self::STATUS_START]);
        if (!isset($this->config['exec'])) {
            die('config exec must be not null!');
        }
        $this->logger->log('process start pid: ' . $this->ppid, 'info', $this->logSaveFileWorker);

        $this->configWorkersByNameNum=[];
        foreach ($this->config['exec'] as $key => $value) {
            if (!isset($value['bin']) || !isset($value['binArgs'])) {
                $this->logger->log('config bin/binArgs must be not null!', 'error', $this->logSaveFileWorker);
            }

            $workOne['bin']     = $value['bin'];
            $workOne['name']    = $value['name'];
            $workOne['binArgs'] = $value['binArgs'];
            //开启多个子进程
            for ($i = 0; $i < $value['workNum']; ++$i) {
                $this->reserveExec($i, $workOne, self::CHILD_PROCESS_CAN_RESTART);
            }
            $this->configWorkersByNameNum[$value['name']] = $value['workNum'];
        }

        if (empty($this->timer)) {
            $this->registSignal();
            $this->registTimer();
        }//启动成功，修改状态

        $this->saveMasterData([self::REDIS_MASTER_KEY=>self::STATUS_RUNNING]);
    }

    public function startByWorkerName($workName)
    {
        $this->saveMasterData([self::REDIS_WORKER_STATUS_KEY . $workName=>self::STATUS_START]);
        foreach ($this->config['exec'] as $key => $value) {
            if ($value['name'] != $workName) {
                continue;
            }
            if (!isset($value['bin']) || !isset($value['binArgs'])) {
                $this->logger->log('config bin/binArgs must be not null!', 'error', $this->logSaveFileWorker);
            }

            $workOne['bin']     = $value['bin'];
            $workOne['name']    = $value['name'];
            $workOne['binArgs'] = $value['binArgs'];
            //开启多个子进程
            for ($i = 0; $i < $value['workNum']; ++$i) {
                $this->reserveExec($i, $workOne);
            }
        }

        $this->saveMasterData([self::REDIS_WORKER_STATUS_KEY . $workName=>self::STATUS_RUNNING]);
    }

    /**
     * 启动子进程，跑业务代码
     *
     * @param int    $workNum
     * @param mixed  $workOne
     * @param string $workerType 是否会重启 canRestart|unRestart
     */
    public function reserveExec($workNum, $workOne, $workerType=self::CHILD_PROCESS_CAN_RESTART)
    {
        $reserveProcess = new \Swoole\Process(function ($worker) use ($workNum, $workOne) {
            usleep($this->sleepTime * 1000); // usleep单位是微妙，$this->sleepTime * 1000 转为毫秒
            $this->checkMpid($worker);
            try {
                $this->logger->log('Worker exec: ' . $workOne['bin'] . ' ' . implode(' ', $workOne['binArgs']), 'info', $this->logSaveFileWorker);
                //执行一个外部程序
                $worker->exec($workOne['bin'], $workOne['binArgs']);
            } catch (\Throwable $e) {
                Utils::catchError($this->logger, $e);
            } catch (\Exception $e) {
                Utils::catchError($this->logger, $e);
            }
            $this->logger->log('worker id: ' . $workNum . ' is done!!!', 'info', $this->logSaveFileWorker);
            $worker->exit(0);
        });
        $pid                                       = $reserveProcess->start();
        $this->workers[$pid]                       = $reserveProcess;
        $this->workersInfoList[$pid]['type']       = $workerType;
        $this->workersInfoList[$pid]['workOne']    = $workOne;
        $this->setWorkerList(self::REDIS_WORKER_MEMBER_KEY . $workOne['name'], $pid, 'add');
        $this->workersByPidName[$pid]              = $workOne['name'];
        $this->saveMasterData([self::REDIS_WORKER_STATUS_KEY . $workOne['name'] =>self::STATUS_RUNNING]);
        $this->logger->log('worker id: ' . $workNum . ' pid: ' . $pid . ' is start... ' . $workerType, 'info', $this->logSaveFileWorker);
    }

    /**
     * 注册信号.
     */
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
                // 捕获回收子进程异常
                try {
                    $ret = \Swoole\Process::wait(false);
                } catch (\Exception $e) {
                    $this->logger->log('signoError: ' . $signo . $e->getMessage(), 'error', Logs::LOG_SAVE_FILE_WORKER);
                }
                if ($ret) {
                    $pid           = $ret['pid'];
                    $childProcess = $this->workers[$pid];
                    $workName = $this->workersByPidName[$pid];
                    $workerType = $this->workersInfoList[$pid]['type'];
                    $this->status=$this->getMasterData(self::REDIS_MASTER_KEY);
                    //根据wokerName，获取其运行状态
                    $workNameStatus=$this->getMasterData(self::REDIS_WORKER_STATUS_KEY . $workName);
                    //子进程为可重启进程，主进程状态为start,running且子进程组不是recover状态才需要拉起子进程
                    if (self::CHILD_PROCESS_CAN_RESTART == $workerType && self::STATUS_RECOVER != $workNameStatus && (self::STATUS_RUNNING == $this->status || self::STATUS_START == $this->status)) {
                        try {
                            $i=0;
                            //重启有可能失败，最多尝试10次
                            while ($i <= 10) {
                                $newPid  = $childProcess->start();
                                if ($newPid > 0) {
                                    break;
                                }
                                $this->logger->log($workName . '子进程重启失败，子进程尝试' . $i . '次重启', 'info', $this->logSaveFileWorker);

                                ++$i;
                            }
                        } catch (\Throwable $e) {
                            Utils::catchError($this->logger, $e, 'error: woker restart fail...');
                        } catch (\Exception $e) {
                            Utils::catchError($this->logger, $e, 'error: woker restart fail...');
                        }
                        if ($newPid > 0) {
                            $this->logger->log("Worker Restart, kill_signal={$ret['signal']} PID=" . $newPid, 'info', $this->logSaveFileWorker);
                            $this->workers[$newPid] = $childProcess;
                            $this->workersInfoList[$newPid]['type']      = $workerType;
                            $this->workersInfoList[$newPid]['workOne']   = $this->workersInfoList[$pid]['workOne'];
                            $this->setWorkerList(self::REDIS_WORKER_MEMBER_KEY . $workName, $newPid, 'add');
                            $this->workersByPidName[$newPid]        = $workName;
                            $this->saveMasterData([self::REDIS_WORKER_STATUS_KEY . $workName=>self::STATUS_RUNNING]);
                        } else {
                            $this->saveMasterData([self::REDIS_WORKER_STATUS_KEY . $workName=>self::STATUS_RECOVER]);
                            $this->logger->log($workName . '子进程重启失败，该组子进程进入recover状态', 'info', $this->logSaveFileWorker);
                        }
                    }
                    // 动态子进程
                    if (self::CHILD_PROCESS_CAN_NOT_RESTART == $workerType) {
                        --$this->dynamicWorkerNum[$workName];
                    }
                    $this->logger->log("Worker Exit, kill_signal={$ret['signal']} PID=" . $pid, 'info', $this->logSaveFileWorker);
                    unset($this->workers[$pid], $this->workersByPidName[$pid], $this->workersInfoList[$pid]);
                    $this->setWorkerList(self::REDIS_WORKER_MEMBER_KEY . $workName, $pid, 'del');
                    $this->logger->log('Worker count: ' . \count($this->workers) . '  [' . $workName . ']  ' . $this->configWorkersByNameNum[$workName], 'info', $this->logSaveFileWorker);
                    //如果$this->workers为空，且主进程状态为wait，说明所有子进程安全退出，这个时候主进程退出
                    if (empty($this->workers) && self::STATUS_WAIT == $this->status) {
                        $this->logger->log('主进程收到所有信号子进程的退出信号，子进程安全退出完成', 'info', $this->logSaveFileWorker);
                        $this->exitMaster();
                    }
                } else {
                    break;
                }
            }
        });
    }

    /**
     * 注册定时器.
     */
    public function registTimer()
    {
        $this->timer=\Swoole\Timer::tick($this->checkTickTimer, function ($timerId) {
            $workNameStatus = '';
            foreach ($this->configWorkersByNameNum as $workName => $value) {
                $this->status  =$this->getMasterData(self::REDIS_MASTER_KEY);
                $workNameStatus=$this->getMasterData(self::REDIS_WORKER_STATUS_KEY . $workName);
                $workNameMembers=$this->getWorkerList(self::REDIS_WORKER_MEMBER_KEY . $workName);
                $this->checkChildProcess($workName, $workNameMembers);
                $count=\count($workNameMembers);
                if ($count <= 0) {
                    $this->saveMasterData([self::REDIS_WORKER_STATUS_KEY . $workName=>self::STATUS_START]);
                    $this->startByWorkerName($workName);
                    $this->logger->log('主进程 recover 子进程：' . $workName, 'info', $this->logSaveFileWorker);
                }
                $this->logger->log('主进程状态：' . $this->status . ' 数量：' . \count($this->workers), 'info', $this->logSaveFileWorker);
                $this->logger->log('[' . $workName . ']子进程状态：' . $workNameStatus . ' 数量：' . $count . ' pids:' . serialize($workNameMembers), 'info', $this->logSaveFileWorker);
            }
            // 动态进程控制todo
            foreach ($this->config['exec'] as $key => $value) {
                if (!isset($value['dynamicWorkNum']) || $value['dynamicWorkNum'] < 1 || !isset($value['queueNumCacheKey']) || !$value['queueNumCacheKey']) {
                    continue;
                }
                if (!isset($value['bin']) || !isset($value['binArgs'])) {
                    $this->logger->log('config bin/binArgs must be not null!', 'error', $this->logSaveFileWorker);
                }
                $queueNum = $this->getCacheData($value['queueNumCacheKey']);
                $this->dynamicWorkerNum[$value['name']] = isset($this->dynamicWorkerNum[$value['name']]) ? $this->dynamicWorkerNum[$value['name']] : 0;
                if ($queueNum < $this->queueMaxNum || $this->dynamicWorkerNum[$value['name']] >= $value['dynamicWorkNum']) {
                    continue;
                }
                $workOne['bin']   = $value['bin'];
                $workOne['name']  = $value['name'];
                $workOne['binArgs']= $value['binArgs'];
                $canStartNum = $value['dynamicWorkNum'] - $this->dynamicWorkerNum[$value['name']];

                //开启多个子进程
                for ($i = 0; $i < $canStartNum; ++$i) {
                    $this->reserveExec($i, $workOne, self::CHILD_PROCESS_CAN_NOT_RESTART);
                    ++$this->dynamicWorkerNum[$value['name']];
                }
            }
        });
    }

    //检查子进程是否还活着
    private function checkChildProcess($workName, $members)
    {
        foreach ($members as $key => $pid) {
            if ($pid) {
                if (!@\Swoole\Process::kill($pid, 0)) {
                    unset($this->workers[$pid], $this->workersByPidName[$pid]);
                    $this->setWorkerList(self::REDIS_WORKER_MEMBER_KEY . $workName, $pid, 'del');
                    $this->logger->log('子进程异常退出：' . $pid . ' name：' . $workName, 'error', $this->logSaveFileWorker);
                } else {
                    $this->logger->log('子进程正常：' . $pid . ' name：' . $workName, 'info', $this->logSaveFileWorker);
                }
            }
        }
    }

    //平滑等待子进程退出之后，再退出主进程
    private function killWorkersAndExitMaster()
    {
        //修改主进程状态为stop
        $this->status              =self::STATUS_STOP;
        $this->saveMasterData([self::REDIS_MASTER_KEY=>self::STATUS_STOP]);

        if ($this->workers) {
            foreach ($this->workers as $pid => $worker) {
                //强制杀workers子进程
                if (true == \Swoole\Process::kill($pid)) {
                    unset($this->workers[$pid]);
                    $this->logger->log('子进程[' . $pid . ']收到强制退出信号,退出成功', 'info', $this->logSaveFileWorker);
                } else {
                    $this->logger->log('子进程[' . $pid . ']收到强制退出信号,但退出失败', 'info', $this->logSaveFileWorker);
                }

                $this->logger->log('Worker count: ' . \count($this->workers), 'info', $this->logSaveFileWorker);
            }
        }
        $this->exitMaster();
    }

    //强制杀死子进程并退出主进程
    private function waitWorkers()
    {
        //修改主进程状态为wait

        $this->saveMasterData([self::REDIS_MASTER_KEY=>self::STATUS_WAIT]);
        $this->status = self::STATUS_WAIT;
        foreach ($this->configWorkersByNameNum as $key => $value) {
            $workName                  =$key;
            $this->saveMasterData([self::REDIS_WORKER_STATUS_KEY . $workName=>self::STATUS_WAIT]);
        }
    }

    //退出主进程
    private function exitMaster()
    {
        @unlink($this->pidFile);
        $this->clearMasterData();
        $this->logger->log('Time: ' . microtime(true) . '主进程' . $this->ppid . '退出', 'info', $this->logSaveFileWorker);
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
        if (\function_exists('swoole_set_process_name') && PHP_OS != 'Darwin') {
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
        return file_get_contents($this->pidFile);
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
            $value && $this->redis->del(self::REDIS_WORKER_STATUS_KEY . $key);
            $value && $this->redis->del(self::REDIS_WORKER_MEMBER_KEY . $key);
            $this->logger->log('主进程退出前删除woker redis key： ' . $key, 'info', $this->logSaveFileWorker);
        }
        $this->redis->del(self::REDIS_MASTER_KEY);

        $this->logger->log('主进程退出前删除master redis key： status', 'info', $this->logSaveFileWorker);
    }

    private function setWorkerList($key, $member, $opt='add')
    {
        $this->redis = $this->getRedis();
        if ('add' == $opt) {
            return $this->redis->sAdd($key, $member);
        } elseif ('del' == $opt) {
            return $this->redis->sRemove($key, $member);
        }
    }

    /**
     * 获取子进程列表.
     *
     * @param string $key
     *
     * @return mixed
     */
    private function getWorkerList($key)
    {
        $this->redis = $this->getRedis();

        return $this->redis->sMembers($key);
    }

    /**
     * 获取主进程数据.
     *
     * @param string $key
     *
     * @return mixed
     */
    private function getMasterData($key)
    {
        $this->redis = $this->getRedis();
        if ($key) {
            return $this->redis->get($key);
        }
    }

    /**
     * 获取缓存数据.
     *
     * @param string $key
     *
     * @return mixed
     */
    private function getCacheData($key)
    {
        $this->redis = $this->getRedis();
        if ($key) {
            return $this->redis->get($key);
        }

        return false;
    }

    /**
     * 获取redis实例.
     */
    private function getRedis()
    {
        if ($this->redis && $this->redis->ping()) {
            return $this->redis;
        }
        $this->redis   = new XRedis($this->config['redis']);

        return $this->redis;
    }
}
