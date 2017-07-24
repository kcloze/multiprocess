<?php

/*
 * This file is part of PHP CS Fixer.
 * (c) kcloze <pei.greet@qq.com>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Kcloze\MultiProcess;

class Console
{
    public $logger    = null;
    private $config   = [];

    public function __construct($config)
    {
        $this->config = $config;
        $this->logger = new Logs($config['logPath']);
    }

    public function run()
    {
        $this->getOpt();
    }

    public function start()
    {
        //启动
        $this->logger->log('starting...');
        try {
            $process = new Process($this->config);
            $process->start();
        } catch (\Exception $e) {
            $this->logger->log($e->getMessage());
            die('ALL ERROR: ' . $e->getMessage());
        }
    }

    public function stop()
    {
        $masterPidFile=$this->config['logPath'] . '/' . Process::PID_FILE;
        if (file_exists($masterPidFile)) {
            $ppid=file_get_contents($masterPidFile);
            if (empty($ppid)) {
                exit('service is not running');
            }
            if (function_exists('posix_kill')) {
                $return=posix_kill($ppid, SIGTERM);
                if ($return) {
                    $this->logger->log('[pid: ' . $ppid . '] has been stopped success');
                } else {
                    $this->logger->log('[pid: ' . $ppid . '] has been stopped fail');
                }
            } else {
                system('kill -9' . $ppid);
                $this->logger->log('[pid: ' . $ppid . '] has been stopped success');
            }
        } else {
            exit('service is not running');
        }
    }

    public function restart()
    {
        $this->logger->log('restarting...');
        $this->stop();
        $this->start();
    }

    public function getOpt()
    {
        global $argv;
        if (empty($argv[1])) {
            $this->printHelpMessage();
            exit(1);
        }
        $opt=$argv[1];
        switch ($opt) {
            case 'start':
                $this->start();
                break;
            case 'stop':
                $this->stop();
                break;
            case 'restart':
                $this->restart();
                break;
            case 'help':
                $this->printHelpMessage();
                break;

            default:
                $this->printHelpMessage();
                break;
        }
    }

    public function printHelpMessage()
    {
        $msg=<<<'EOF'
NAME
      run.php - manage daemons

SYNOPSIS
      run.php command [options]
          Manage multi process daemons.


WORKFLOWS


      help [command]
      Show this help, or workflow help for command.

      list
      Show a list of available daemons.

      restart
      Stop, then start the standard daemon loadout.

      start
      Start the standard configured collection of Phabricator daemons. This
      is appropriate for most installs. Use phd launch to customize which
      daemons are launched.

      status
      Show status of running daemons.

      stop
      Stop all running daemons, or specific daemons identified by PIDs. Use
      run.php status to find PIDs.

EOF;
        echo $msg;
    }
}
