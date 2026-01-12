<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use app\common\service\core\TradeService;
use app\common\service\core\MarketService;
use app\common\service\core\AssetService;

/**
 * 新版撮合命令 (V2)
 * 
 * 使用重构后的 Service 层执行撮合
 * 
 * 使用方法：
 * php think matching:v2
 * php think matching:v2 --force  # 强制执行（忽略场次时间限制）
 * 
 * @package app\command
 * @version 2.0
 * @date 2025-12-28
 */
class MatchingV2 extends Command
{
    protected function configure()
    {
        $this->setName('matching:v2')
            ->setDescription('新版撮合命令（使用重构后的Service层）')
            ->addOption('force', 'f', \think\console\input\Option::VALUE_NONE, '强制撮合（忽略场次时间限制）')
            ->addOption('session', 's', \think\console\input\Option::VALUE_OPTIONAL, '指定场次ID')
            ->addOption('dry-run', null, \think\console\input\Option::VALUE_NONE, '模拟运行（不实际执行交易）');
    }

    protected function execute(Input $input, Output $output)
    {
        $startTime = microtime(true);
        
        // 解析参数
        $forceMode = (bool)$input->getOption('force');
        $dryRun = (bool)$input->getOption('dry-run');
        $specifiedSession = $input->getOption('session');
        
        $runMode = $forceMode ? '强制撮合' : '正常运行';
        if ($dryRun) {
            $runMode .= ' (模拟)';
        }
        
        $output->writeln('================================================================================');
        $output->writeln('[' . date('Y-m-d H:i:s') . '] 🚀 新版撮合命令 V2 - ' . $runMode);
        $output->writeln('================================================================================');
        
        $totalStats = [
            'matched' => 0,
            'failed' => 0,
            'refunded' => 0,
            'off_shelf' => 0,
        ];
        
        try {
            // 1. 获取需要处理的场次
            $sessions = $this->getSessionsToProcess($specifiedSession, $forceMode, $output);
            
            if (empty($sessions)) {
                $output->writeln('  📭 没有需要处理的场次');
                return 0;
            }
            
            $output->writeln('  📋 找到 ' . count($sessions) . ' 个待处理场次');
            
            // 2. 逐个场次处理
            foreach ($sessions as $session) {
                $sessionId = (int)$session['id'];
                $sessionTitle = $session['title'] ?? "场次#{$sessionId}";
                
                $output->writeln('');
                $output->writeln("  🎯 处理场次【{$sessionTitle}】(ID: {$sessionId})");
                $output->writeln("     交易时间: {$session['start_time']} - {$session['end_time']}");
                
                // 统计信息
                $this->printSessionStats($sessionId, $output);
                
                if ($dryRun) {
                    $output->writeln('     ⚠️  模拟模式，跳过实际执行');
                    continue;
                }
                
                // 执行撮合
                $stats = TradeService::matchPool($sessionId);
                
                // 累计统计
                $totalStats['matched'] += $stats['matched'];
                $totalStats['failed'] += $stats['failed'];
                $totalStats['refunded'] += $stats['refunded'];
                $totalStats['off_shelf'] += $stats['off_shelf'];
                
                // 输出结果
                $output->writeln("     ✅ 撮合完成: 成功 {$stats['matched']} | 失败 {$stats['failed']} | 退款 {$stats['refunded']} | 下架 {$stats['off_shelf']}");
            }
            
        } catch (\Exception $e) {
            $output->writeln('');
            $output->writeln('  ❌ 执行出错: ' . $e->getMessage());
            return 1;
        }
        
        // 输出总结
        $elapsed = round(microtime(true) - $startTime, 2);
        
        $output->writeln('');
        $output->writeln('================================================================================');
        $output->writeln('📊 执行总结');
        $output->writeln('--------------------------------------------------------------------------------');
        $output->writeln("   撮合成功: {$totalStats['matched']}");
        $output->writeln("   撮合失败: {$totalStats['failed']}");
        $output->writeln("   退款处理: {$totalStats['refunded']}");
        $output->writeln("   流拍下架: {$totalStats['off_shelf']}");
        $output->writeln("   耗时: {$elapsed} 秒");
        $output->writeln('================================================================================');
        
        return 0;
    }
    
    /**
     * 获取需要处理的场次
     */
    private function getSessionsToProcess(?string $specifiedSession, bool $forceMode, Output $output): array
    {
        // 如果指定了场次ID
        if ($specifiedSession !== null) {
            $sessionId = (int)$specifiedSession;
            $session = MarketService::getSession($sessionId);
            if (!$session) {
                $output->writeln("  ⚠️  指定的场次 #{$sessionId} 不存在");
                return [];
            }
            return [$session];
        }
        
        // 获取所有启用的场次
        $sessions = Db::name('collection_session')
            ->where('status', 1)
            ->select()
            ->toArray();
        
        if (empty($sessions)) {
            return [];
        }
        
        $currentTime = date('H:i');
        $processableSessions = [];
        
        foreach ($sessions as $session) {
            $startTime = $session['start_time'] ?? '';
            $endTime = $session['end_time'] ?? '';
            
            // 强制模式下跳过时间检查
            if ($forceMode) {
                $processableSessions[] = $session;
                continue;
            }
            
            // 正常模式：只处理已结束的场次
            if (!empty($endTime) && $currentTime > $endTime) {
                // 检查是否有待处理的买单
                $pendingCount = Db::name('collection_matching_pool')
                    ->where('session_id', $session['id'])
                    ->where('status', 'pending')
                    ->count();
                
                if ($pendingCount > 0) {
                    $processableSessions[] = $session;
                }
            }
        }
        
        return $processableSessions;
    }
    
    /**
     * 打印场次统计信息
     */
    private function printSessionStats(int $sessionId, Output $output): void
    {
        // 参与人数
        $participantCount = Db::name('collection_matching_pool')
            ->where('session_id', $sessionId)
            ->where('status', 'pending')
            ->count('DISTINCT user_id');
        
        // 买单数量
        $buyOrderCount = Db::name('collection_matching_pool')
            ->where('session_id', $sessionId)
            ->where('status', 'pending')
            ->count();
        
        // 寄售数量
        $consignmentCount = Db::name('collection_consignment')
            ->alias('c')
            ->join('collection_item i', 'c.item_id = i.id')
            ->where('i.session_id', $sessionId)
            ->where('c.status', 1)
            ->count();
        
        $output->writeln("     👥 参与人数: {$participantCount} | 买单: {$buyOrderCount} | 寄售: {$consignmentCount}");
    }
}
