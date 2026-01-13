<?php

namespace app\admin\controller\finance;

use Throwable;
use app\common\controller\Backend;
use ba\Terminal;

class ScriptMonitor extends Backend
{
    /**
     * 脚本配置
     */
    protected array $scripts = [
        'daily' => [
            'name' => '每日返息',
            'command' => 'finance:income:daily',
            'cron' => '0 1 * * *',
            'log_file' => '/tmp/finance_income_daily.log',
            'description' => '每天凌晨1点执行，自动发放每日返息收益',
        ],
        'period' => [
            'name' => '周期返息',
            'command' => 'finance:income:period',
            'cron' => '0 2 * * *',
            'log_file' => '/tmp/finance_income_period.log',
            'description' => '每天凌晨2点执行，自动发放周期返息收益',
        ],
        'stage' => [
            'name' => '阶段返息',
            'command' => 'finance:income:stage',
            'cron' => '0 3 * * *',
            'log_file' => '/tmp/finance_income_stage.log',
            'description' => '每天凌晨3点执行，自动发放阶段返息收益',
        ],
        'settle' => [
            'name' => '到期结算',
            'command' => 'finance:settle',
            'cron' => '0 * * * *',
            'log_file' => '/tmp/finance_settle.log',
            'description' => '每小时执行一次，自动处理到期的理财订单',
        ],
        'consignment_expire' => [
            'name' => '寄售过期流拍',
            'command' => 'consignment:expire',
            'cron' => '0 4 * * *',
            'log_file' => '/tmp/consignment_expire.log',
            'description' => '每天凌晨4点执行，自动处理超过配置天数未售出的寄售记录，标记为流拍失败（天数可在抽奖次数配置页面设置）',
        ],
        'collection_mining_check' => [
            'name' => '藏品强制锁仓检查',
            'command' => 'collection:mining:check',
            'cron' => '0 5 * * *',
            'log_file' => '/tmp/collection_mining_check.log',
            'description' => '每天凌晨5点执行，自动检查触发条件，将符合条件的藏品转为矿机（配置可在抽奖次数配置页面设置）',
        ],
        'collection_mining_dividend' => [
            'name' => '矿机每日分红',
            'command' => 'collection:mining:dividend',
            'cron' => '0 6 * * *',
            'log_file' => '/tmp/collection_mining_dividend.log',
            'description' => '每天凌晨6点执行，自动发放矿机每日分红（配置可在抽奖次数配置页面设置）',
        ],
        'collection_matching' => [
            'name' => '藏品撮合池撮合',
            'command' => 'collection:matching',
            'cron' => '* * * * *',
            'log_file' => '/tmp/collection_matching.log',
            'description' => '每分钟执行一次，自动撮合撮合池中的竞价购买记录（按权重从高到低排序）',
        ],
        'collection_daily_dividend' => [
            'name' => '藏品每日分红',
            'command' => 'collection:daily:dividend',
            'cron' => '0 7 * * *',
            'log_file' => '/tmp/collection_daily_dividend.log',
            'description' => '每天凌晨7点执行，自动发放藏品每日分红（配置可在抽奖次数配置页面设置）',
        ],
    ];

    /**
     * 获取脚本状态列表
     * @throws Throwable
     */
    public function index(): void
    {
        $list = [];
        
        foreach ($this->scripts as $key => $script) {
            $status = $this->checkScriptStatus($script);
            $list[] = [
                'key' => $key,
                'name' => $script['name'],
                'command' => $script['command'],
                'cron' => $script['cron'],
                'description' => $script['description'],
                'status' => $status['status'], // running, normal, warning, error
                'status_text' => $status['status_text'],
                'last_run_time' => $status['last_run_time'],
                'last_run_time_text' => $status['last_run_time_text'],
                'log_file' => $script['log_file'],
                'log_exists' => $status['log_exists'],
                'log_size' => $status['log_size'],
                'log_size_text' => $status['log_size_text'],
                'is_running' => $status['is_running'] ?? false,
            ];
        }

        $this->success('', [
            'list' => $list,
        ]);
    }

    /**
     * 检查脚本状态
     * @param array $script
     * @return array
     */
    protected function checkScriptStatus(array $script): array
    {
        $logFile = $script['log_file'];
        $logExists = file_exists($logFile);
        $logSize = 0;
        $lastRunTime = 0;
        $status = 'error';
        $statusText = '未知';
        $isRunning = $this->isScriptRunning($script);

        if ($isRunning) {
            $status = 'running';
            $statusText = '运行中';
        } elseif ($logExists) {
            $logSize = filesize($logFile);
            $lastRunTime = filemtime($logFile);
            
            // 判断状态
            $now = time();
            $timeDiff = $now - $lastRunTime;
            
            // 根据脚本类型判断超时时间
            $timeout = 86400; // 默认24小时
            if ($script['command'] === 'finance:settle') {
                $timeout = 3600; // 结算脚本每小时执行，超时时间设为1小时
            } elseif ($script['command'] === 'consignment:expire') {
                $timeout = 86400; // 寄售过期流拍每天执行，超时时间设为24小时
            } elseif ($script['command'] === 'collection:matching') {
                $timeout = 120; // 撮合脚本每分钟执行，超时时间设为2分钟
            }
            
            if ($timeDiff < $timeout) {
                $status = 'normal';
                $statusText = '运行正常';
            } elseif ($timeDiff < $timeout * 2) {
                $status = 'warning';
                $statusText = '可能异常';
            } else {
                $status = 'error';
                $statusText = '运行异常';
            }
        } else {
            // 日志文件不存在时，显示为"未运行"而不是"错误"
            $status = 'warning';
            $statusText = '未运行';
        }

        return [
            'status' => $status,
            'status_text' => $statusText,
            'last_run_time' => $lastRunTime,
            'last_run_time_text' => $lastRunTime ? date('Y-m-d H:i:s', $lastRunTime) : '从未运行',
            'log_exists' => $logExists,
            'log_size' => $logSize,
            'log_size_text' => $this->formatFileSize($logSize),
            'is_running' => $isRunning,
        ];
    }

    /**
     * 检查脚本是否正在运行
     * @param array $script
     * @return bool
     */
    protected function isScriptRunning(array $script): bool
    {
        // 提取命令的关键部分用于查找进程
        // 命令格式类似: finance:income:daily
        $commandPattern = preg_quote($script['command'], '/');
        
        // 使用 ps 命令查找进程
        // 匹配格式: php think finance:income:daily
        $psCommand = "ps aux | grep -E 'php.*think.*{$commandPattern}' | grep -v grep";
        
        // 优先使用 exec，如果被禁用则返回 false
        if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
            $output = '';
            @\exec($psCommand, $output);
            return !empty($output);
        }
        
        // 如果 exec 不可用，尝试使用 shell_exec
        if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
            $output = @\shell_exec($psCommand);
            return !empty(trim($output ?? ''));
        }
        
        // 如果都不可用，返回 false
        return false;
    }

    /**
     * 格式化文件大小
     * @param int $size
     * @return string
     */
    protected function formatFileSize(int $size): string
    {
        if ($size < 1024) {
            return $size . ' B';
        } elseif ($size < 1024 * 1024) {
            return round($size / 1024, 2) . ' KB';
        } else {
            return round($size / (1024 * 1024), 2) . ' MB';
        }
    }

    /**
     * 执行脚本
     * @throws Throwable
     */
    public function run(): void
    {
        $key = $this->request->param('key');
        $force = (bool)$this->request->param('force/d', 0);
        
        if (!isset($this->scripts[$key])) {
            $this->error('脚本不存在');
        }

        $script = $this->scripts[$key];
        // 根据脚本类型确定命令配置键
        if ($key === 'collection_matching') {
            $commandKey = 'collection.matching';
        } elseif ($key === 'collection_daily_dividend') {
            // collection_daily_dividend -> collection.daily_dividend
            $commandKey = 'collection.daily_dividend';
        } elseif (strpos($key, 'collection_mining_') === 0) {
            // collection_mining_check -> collection.mining.check
            // collection_mining_dividend -> collection.mining.dividend
            $subKey = str_replace('collection_mining_', '', $key);
            $commandKey = 'collection.mining.' . $subKey;
        } elseif (strpos($key, 'consignment_') === 0) {
            $commandKey = 'consignment.' . str_replace('consignment_', '', $key);
        } else {
            $commandKey = 'finance.' . $key;
        }

        // 获取命令配置
        $command = Terminal::getCommand($commandKey);
        if (!$command) {
            $this->error('执行失败，请检查命令配置');
        }

        // 可选强制参数：仅对 collection_matching 生效
        $execCommand = $command['command'];
        if ($key === 'collection_matching' && $force) {
            $execCommand .= ' --force';
        }

        // 使用 proc_open 执行命令，确保使用正确的工作目录
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            $this->error('系统不支持 proc_open 函数');
        }

        $descriptorsPec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']]; 
        // getCommand 已经处理了 cwd，如果为空会拼接 root_path()
        $cwd = $command['cwd'] ?? app()->getRootPath();
        $process = proc_open($execCommand, $descriptorsPec, $pipes, $cwd);
        
        if (!is_resource($process)) {
            $this->error('执行失败，无法启动进程');
        }

        // 读取输出
        $info = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // 过滤输出
        $output = Terminal::outputFilter($info . $error);

        // 将输出写入日志文件，以便监控系统检测
        $logFile = $script['log_file'];
        $logDir = dirname($logFile);
        
        // 确保目录存在
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // 追加日志内容，包含时间戳
        $logContent = "\n" . str_repeat('=', 80) . "\n";
        $logContent .= "执行时间: " . date('Y-m-d H:i:s') . "\n";
        $logContent .= "执行命令: " . $execCommand . "\n";
        $logContent .= str_repeat('=', 80) . "\n";
        $logContent .= $output . "\n";
        
        // 追加到日志文件
        file_put_contents($logFile, $logContent, FILE_APPEND | LOCK_EX);
        
        // 确保文件权限正确
        chmod($logFile, 0666);

        $this->success('执行成功', [
            'output' => $output,
        ]);
    }

    /**
     * 停止脚本运行
     * @throws Throwable
     */
    public function stop(): void
    {
        $key = $this->request->param('key');
        
        if (!isset($this->scripts[$key])) {
            $this->error('脚本不存在');
        }

        $script = $this->scripts[$key];
        
        // 检查脚本是否正在运行
        if (!$this->isScriptRunning($script)) {
            $this->error('脚本未在运行中');
        }

        // 根据脚本类型确定命令配置键
        // 根据脚本类型确定命令配置键
        if ($key === 'collection_matching') {
            $commandKey = 'collection.matching';
        } elseif ($key === 'collection_daily_dividend') {
            $commandKey = 'collection.daily_dividend';
        } elseif (strpos($key, 'collection_mining_') === 0) {
            // collection_mining_check -> collection.mining.check
            // collection_mining_dividend -> collection.mining.dividend
            $subKey = str_replace('collection_mining_', '', $key);
            $commandKey = 'collection.mining.' . $subKey;
        } elseif (strpos($key, 'consignment_') === 0) {
            $commandKey = 'consignment.' . str_replace('consignment_', '', $key);
        } else {
            $commandKey = 'finance.' . $key;
        }
        
        // 获取命令配置
        $command = Terminal::getCommand($commandKey);
        if (!$command) {
            $this->error('执行失败，请检查命令配置');
        }

        // 提取命令的关键部分用于查找进程
        $commandPattern = preg_quote($script['command'], '/');
        
        // 查找进程ID
        $psCommand = "ps aux | grep -E 'php.*think.*{$commandPattern}' | grep -v grep";
        
        // 优先使用 exec，如果被禁用则使用 shell_exec
        $output = '';
        if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
            @\exec($psCommand, $output);
            $output = implode("\n", $output);
        } elseif (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
            $output = @\shell_exec($psCommand);
        } else {
            $this->error('系统函数 exec 和 shell_exec 均被禁用，无法停止脚本');
        }
        
        if (empty(trim($output ?? ''))) {
            $this->error('未找到运行中的进程');
        }

        // 解析进程ID并终止
        $killedCount = 0;
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            if (preg_match('/^\S+\s+(\d+)/', $line, $matches)) {
                $pid = (int)$matches[1];
                if ($pid > 0) {
                    // 优先使用 posix_kill，如果不可用则使用 kill 命令
                    if (function_exists('posix_kill')) {
                        // 发送 SIGTERM 信号终止进程
                        \posix_kill($pid, SIGTERM);
                        $killedCount++;
                        
                        // 等待一下，如果进程还在运行，强制终止
                        \usleep(500000); // 等待0.5秒
                        if (\posix_kill($pid, 0)) { // 检查进程是否还存在
                            \posix_kill($pid, SIGKILL); // 强制终止
                        }
                    } else {
                        // 使用 kill 命令终止进程
                        @\exec("kill -TERM {$pid} 2>&1", $killOutput, $killReturn);
                        $killedCount++;
                        
                        // 等待一下，如果进程还在运行，强制终止
                        \usleep(500000); // 等待0.5秒
                        @\exec("kill -0 {$pid} 2>&1", $checkOutput, $checkReturn);
                        if ($checkReturn === 0) { // 进程还存在
                            @\exec("kill -KILL {$pid} 2>&1"); // 强制终止
                        }
                    }
                }
            }
        }

        if ($killedCount > 0) {
            // 记录停止日志
            $logFile = $script['log_file'];
            $logDir = dirname($logFile);
            
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            $logContent = "\n" . str_repeat('=', 80) . "\n";
            $logContent .= "停止时间: " . date('Y-m-d H:i:s') . "\n";
            $logContent .= "停止命令: " . $script['command'] . "\n";
            $logContent .= "已终止进程数: " . $killedCount . "\n";
            $logContent .= str_repeat('=', 80) . "\n";
            
            file_put_contents($logFile, $logContent, FILE_APPEND | LOCK_EX);
            chmod($logFile, 0666);
            
            $this->success('脚本已停止', [
                'killed_count' => $killedCount,
            ]);
        } else {
            $this->error('停止失败，未找到可终止的进程');
        }
    }

    /**
     * 查看日志
     * @throws Throwable
     */
    public function log(): void
    {
        $key = $this->request->param('key');
        
        if (!isset($this->scripts[$key])) {
            $this->error('脚本不存在');
        }

        $script = $this->scripts[$key];
        $logFile = $script['log_file'];

        // 如果日志文件不存在，创建它并设置可写权限
        if (!file_exists($logFile)) {
            $logDir = dirname($logFile);
            // 确保目录存在
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            // 创建空日志文件并设置可写权限
            file_put_contents($logFile, '');
            chmod($logFile, 0666);
        }

        // 确保文件可读
        if (!is_readable($logFile)) {
            chmod($logFile, 0666);
        }

        // 读取最后1000行日志
        $lines = file($logFile);
        $totalLines = count($lines);
        $showLines = min(1000, $totalLines);
        $logContent = implode('', array_slice($lines, -$showLines));

        $this->success('', [
            'content' => $logContent,
            'total_lines' => $totalLines,
            'show_lines' => $showLines,
            'file_size' => filesize($logFile),
            'file_size_text' => $this->formatFileSize(filesize($logFile)),
            'last_modified' => filemtime($logFile),
            'last_modified_text' => date('Y-m-d H:i:s', filemtime($logFile)),
        ]);
    }
}

