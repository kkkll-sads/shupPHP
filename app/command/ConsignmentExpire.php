<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 寄售过期流拍定时任务
 * 用于自动处理超过指定天数未售出的寄售记录，标记为流拍失败
 * 天数配置在系统配置中：consignment_expire_days（默认7天）
 * 
 * 使用方法：
 * php think consignment:expire
 * 
 * Crontab 配置示例（每天凌晨4点执行）：
 * 0 4 * * * cd /www/wwwroot/18.166.209.223 && php think consignment:expire >> /tmp/consignment_expire.log 2>&1
 */
class ConsignmentExpire extends Command
{
    protected function configure()
    {
        $this->setName('consignment:expire')
            ->setDescription('寄售过期流拍自动处理');
    }

    protected function execute(Input $input, Output $output)
    {
        $startTime = microtime(true);
        $output->writeln('========================================');
        $output->writeln('开始处理寄售过期流拍...');
        $output->writeln('执行时间：' . date('Y-m-d H:i:s'));
        $output->writeln('========================================');
        
        // 从系统配置读取寄售失败天数（默认7天）
        $expireDays = (int)get_sys_config('consignment_expire_days', 7);
        if ($expireDays < 1 || $expireDays > 365) {
            $output->writeln("⚠ 警告：配置的寄售失败天数（{$expireDays}）不在有效范围内（1-365），使用默认值7天");
            $expireDays = 7;
        }
        
        $output->writeln("配置的寄售失败天数：{$expireDays} 天");
        
        $now = time();
        $expireSeconds = $expireDays * 24 * 3600;
        $expireTime = $now - $expireSeconds;
        
        $processCount = 0;
        $successCount = 0;
        $errorCount = 0;
        $skipCount = 0; // 跳过的记录数（可能已被处理）

        try {
            // 查询所有超过配置天数且仍在寄售中的记录（status=1）
            // 使用分页处理，避免一次性加载过多数据
            $pageSize = 100;
            $page = 1;
            $hasMore = true;

            while ($hasMore) {
                $consignments = Db::name('collection_consignment')
                    ->where('status', 1) // 寄售中
                    ->where('create_time', '<', $expireTime) // 超过配置的天数
                    ->order('id', 'asc')
                    ->page($page, $pageSize)
                    ->select()
                    ->toArray();

                if (empty($consignments)) {
                    $hasMore = false;
                    break;
                }

                $processCount += count($consignments);
                $output->writeln("第 {$page} 页：找到 " . count($consignments) . " 个过期的寄售记录");

                foreach ($consignments as $consignment) {
                    try {
                        // 双重检查：再次确认状态，避免并发问题
                        $currentConsignment = Db::name('collection_consignment')
                            ->where('id', $consignment['id'])
                            ->where('status', 1)
                            ->lock(true)
                            ->find();

                        if (!$currentConsignment) {
                            $skipCount++;
                            $output->writeln("⚠ 寄售记录 ID:{$consignment['id']} 状态已变更，跳过处理");
                            continue;
                        }

                        Db::startTrans();

                        // 1. 更新寄售记录状态为流拍失败（status=3）
                        $updateResult = Db::name('collection_consignment')
                            ->where('id', $consignment['id'])
                            ->where('status', 1) // 再次确认状态
                            ->update([
                                'status' => 3, // 3=流拍失败
                                'update_time' => $now,
                            ]);

                        if (!$updateResult) {
                            throw new \Exception('更新寄售记录状态失败，可能已被其他进程处理');
                        }

                        // 2. 更新用户藏品寄售状态为未寄售（允许重新寄售）
                        Db::name('user_collection')
                            ->where('id', $consignment['user_collection_id'])
                            ->where('user_id', $consignment['user_id'])
                            ->update([
                                'consignment_status' => 0, // 0=未寄售
                                'update_time' => $now,
                            ]);

                        // 3. 记录活动日志
                        $daysPassed = round(($now - $consignment['create_time']) / (24 * 3600), 1);
                        Db::name('user_activity_log')->insert([
                            'user_id' => $consignment['user_id'],
                            'related_user_id' => 0,
                            'action_type' => 'consignment_expire',
                            'change_field' => 'consignment_status',
                            'change_value' => '3',
                            'before_value' => '1',
                            'after_value' => '3',
                            'remark' => "寄售流拍失败（超过{$expireDays}天未售出）",
                            'extra' => json_encode([
                                'consignment_id' => $consignment['id'],
                                'user_collection_id' => $consignment['user_collection_id'],
                                'item_id' => $consignment['item_id'],
                                'price' => (float)$consignment['price'],
                                'create_time' => $consignment['create_time'],
                                'expire_time' => $now,
                                'days_passed' => $daysPassed,
                            ], JSON_UNESCAPED_UNICODE),
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);

                        Db::commit();
                        $successCount++;
                        
                        $output->writeln("✓ 寄售记录 ID:{$consignment['id']} 已标记为流拍失败，用户ID：{$consignment['user_id']}，已过 {$daysPassed} 天");

                    } catch (\Exception $e) {
                        Db::rollback();
                        $errorCount++;
                        $output->writeln("✗ 寄售记录 ID:{$consignment['id']} 处理失败：" . $e->getMessage());
                        // 记录详细错误信息，但不中断整个流程
                        \think\facade\Log::error('寄售过期流拍处理失败', [
                            'consignment_id' => $consignment['id'] ?? 0,
                            'error_message' => $e->getMessage(),
                            'error_file' => $e->getFile(),
                            'error_line' => $e->getLine(),
                        ]);
                    }
                }

                // 如果本页数据少于分页大小，说明没有更多数据了
                if (count($consignments) < $pageSize) {
                    $hasMore = false;
                } else {
                    $page++;
                }
            }

            $endTime = microtime(true);
            $executionTime = round($endTime - $startTime, 2);

            $output->writeln("\n========================================");
            $output->writeln("寄售过期流拍处理完成！");
            $output->writeln("执行耗时：{$executionTime} 秒");
            $output->writeln("总计处理：{$processCount} 个记录");
            $output->writeln("成功处理：{$successCount} 个");
            $output->writeln("跳过记录：{$skipCount} 个");
            $output->writeln("处理失败：{$errorCount} 个");
            $output->writeln("========================================");
            
            return $errorCount > 0 ? 1 : 0;

        } catch (\Exception $e) {
            $endTime = microtime(true);
            $executionTime = round($endTime - $startTime, 2);
            
            $output->writeln("\n========================================");
            $output->writeln("处理异常：" . $e->getMessage());
            $output->writeln("执行耗时：{$executionTime} 秒");
            $output->writeln("========================================");
            
            // 记录异常日志
            \think\facade\Log::error('寄售过期流拍脚本异常', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'execution_time' => $executionTime,
            ]);
            
            return 1;
        }
    }
}

