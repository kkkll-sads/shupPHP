<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 藏品强制锁仓转为矿机定时任务
 * 用于自动检查触发条件，将符合条件的藏品转为矿机
 * 
 * 触发条件（满足任一）：
 * 1. 连续失败：连续5次寄售都没卖出去（流拍5次）
 * 2. 长期滞销：持有超过7天还没卖掉（或没操作上架）
 * 3. 价格触顶：现价超过了发行价的7倍
 * 
 * 使用方法：
 * php think collection:mining:check
 * 
 * Crontab 配置示例（每天凌晨5点执行）：
 * 0 5 * * * cd /www/wwwroot/18.166.209.223 && php think collection:mining:check >> /tmp/collection_mining_check.log 2>&1
 */
class CollectionMiningCheck extends Command
{
    protected function configure()
    {
        $this->setName('collection:mining:check')
            ->setDescription('藏品强制锁仓转为矿机检查');
    }

    protected function execute(Input $input, Output $output)
    {
        $startTime = microtime(true);
        $output->writeln('========================================');
        $output->writeln('开始检查藏品强制锁仓转为矿机...');
        $output->writeln('执行时间：' . date('Y-m-d H:i:s'));
        $output->writeln('========================================');
        
        // 从系统配置读取参数
        $continuousFailCount = (int)get_sys_config('mining_continuous_fail', 5);
        $longTermDays = (int)get_sys_config('mining_long_term_days', 7);
        $priceTopMultiple = (float)get_sys_config('mining_price_top_multiple', 7.0);
        
        if ($continuousFailCount < 1 || $continuousFailCount > 100) {
            $continuousFailCount = 5;
        }
        if ($longTermDays < 1 || $longTermDays > 365) {
            $longTermDays = 7;
        }
        if ($priceTopMultiple < 1 || $priceTopMultiple > 100) {
            $priceTopMultiple = 7.0;
        }
        
        $output->writeln("配置参数：");
        $output->writeln("  连续失败次数：{$continuousFailCount} 次");
        $output->writeln("  长期滞销天数：{$longTermDays} 天");
        $output->writeln("  价格触顶倍数：{$priceTopMultiple} 倍");
        
        $now = time();
        $longTermSeconds = $longTermDays * 24 * 3600;
        $longTermTime = $now - $longTermSeconds;
        
        $processCount = 0;
        $successCount = 0;
        $errorCount = 0;
        $skipCount = 0;

        try {
            // 查询所有未锁仓的藏品（mining_status = 0）
            $pageSize = 100;
            $page = 1;
            $hasMore = true;

            while ($hasMore) {
                $collections = Db::name('user_collection')
                    ->where('mining_status', 0) // 未锁仓
                    ->where('delivery_status', 0) // 未提货
                    ->order('id', 'asc')
                    ->page($page, $pageSize)
                    ->select()
                    ->toArray();

                if (empty($collections)) {
                    $hasMore = false;
                    break;
                }

                $processCount += count($collections);
                $output->writeln("第 {$page} 页：检查 " . count($collections) . " 个藏品");

                foreach ($collections as $collection) {
                    try {
                        // 双重检查：再次确认状态，避免并发问题
                        $currentCollection = Db::name('user_collection')
                            ->where('id', $collection['id'])
                            ->where('mining_status', 0)
                            ->lock(true)
                            ->find();

                        if (!$currentCollection) {
                            $skipCount++;
                            continue;
                        }

                        $triggerReason = null;
                        $shouldMining = false;

                        // 检查条件1：连续失败次数
                        $failCount = Db::name('collection_consignment')
                            ->where('user_id', $collection['user_id'])
                            ->where('user_collection_id', $collection['id'])
                            ->where('status', 3) // 流拍失败
                            ->order('id', 'desc')
                            ->limit($continuousFailCount)
                            ->count();
                        
                        if ($failCount >= $continuousFailCount) {
                            // 检查是否连续失败
                            $recentConsignments = Db::name('collection_consignment')
                                ->where('user_id', $collection['user_id'])
                                ->where('user_collection_id', $collection['id'])
                                ->order('id', 'desc')
                                ->limit($continuousFailCount)
                                ->select()
                                ->toArray();
                            
                            $isContinuous = true;
                            foreach ($recentConsignments as $consignment) {
                                if ((int)$consignment['status'] !== 3) {
                                    $isContinuous = false;
                                    break;
                                }
                            }
                            
                            if ($isContinuous) {
                                $shouldMining = true;
                                $triggerReason = "连续{$continuousFailCount}次寄售失败（流拍）";
                            }
                        }

                        // 检查条件2：长期滞销（持有超过指定天数还没卖掉或没操作上架）
                        if (!$shouldMining) {
                            $buyTime = (int)$collection['buy_time'];
                            if ($buyTime > 0 && $buyTime < $longTermTime) {
                                // 检查是否从未寄售过，或者最后一次寄售也是失败的
                                $lastConsignment = Db::name('collection_consignment')
                                    ->where('user_id', $collection['user_id'])
                                    ->where('user_collection_id', $collection['id'])
                                    ->order('id', 'desc')
                                    ->find();
                                
                                if (!$lastConsignment || (int)$lastConsignment['status'] === 3) {
                                    $shouldMining = true;
                                    $daysHeld = round(($now - $buyTime) / (24 * 3600));
                                    $triggerReason = "持有超过{$longTermDays}天未售出（已持有{$daysHeld}天）";
                                }
                            }
                        }

                        // 检查条件3：价格触顶（现价超过发行价的指定倍数）
                        if (!$shouldMining) {
                            $item = Db::name('collection_item')
                                ->where('id', $collection['item_id'])
                                ->find();
                            
                            if ($item) {
                                $currentPrice = (float)$item['price'];
                                $issuePrice = (float)($item['issue_price'] ?? $currentPrice);
                                
                                if ($issuePrice > 0 && $currentPrice >= $issuePrice * $priceTopMultiple) {
                                    $shouldMining = true;
                                    $triggerReason = "现价（{$currentPrice}元）超过发行价（{$issuePrice}元）的{$priceTopMultiple}倍";
                                }
                            }
                        }

                        // 如果满足任一条件，转为矿机
                        if ($shouldMining) {
                            Db::startTrans();

                            // 更新藏品状态为矿机
                            Db::name('user_collection')
                                ->where('id', $collection['id'])
                                ->where('mining_status', 0) // 再次确认状态
                                ->update([
                                    'mining_status' => 1, // 1=矿机
                                    'mining_start_time' => $now,
                                    'last_dividend_time' => 0, // 初始化为0，等待第一次分红
                                    'update_time' => $now,
                                ]);

                            // 🔧 清理所有非已售出的寄售记录（避免重复记录）
                            // 清理 status = 0(已取消), 1(寄售中), 3(已下架/流拍) 的记录
                            // 保留 status = 2(已售出) 的历史记录
                            Db::name('collection_consignment')
                                ->where('user_id', $collection['user_id'])
                                ->where('user_collection_id', $collection['id'])
                                ->whereIn('status', [0, 1, 3]) // 清理已取消、寄售中、已下架的记录
                                ->update([
                                    'status' => 0, // 统一标记为已取消
                                    'update_time' => $now,
                                ]);

                            // 更新用户藏品寄售状态
                            Db::name('user_collection')
                                ->where('id', $collection['id'])
                                ->update([
                                    'consignment_status' => 0, // 重置为未寄售
                                    'update_time' => $now,
                                ]);

                            // 如果商品已上架，下架（转为矿机后不再在商城展示）
                            $item = Db::name('collection_item')
                                ->where('id', $collection['item_id'])
                                ->find();
                            
                            if ($item && isset($item['status']) && $item['status'] == '1') {
                                Db::name('collection_item')
                                    ->where('id', $item['id'])
                                    ->update([
                                        'status' => '0',
                                        'update_time' => $now,
                                    ]);
                            }

                            // 记录活动日志
                            Db::name('user_activity_log')->insert([
                                'user_id' => $collection['user_id'],
                                'related_user_id' => 0,
                                'action_type' => 'collection_mining',
                                'change_field' => 'mining_status',
                                'change_value' => '1',
                                'before_value' => '0',
                                'after_value' => '1',
                                'remark' => "藏品强制锁仓转为矿机：{$triggerReason}",
                                'extra' => json_encode([
                                    'user_collection_id' => $collection['id'],
                                    'item_id' => $collection['item_id'],
                                    'item_title' => $collection['title'],
                                    'trigger_reason' => $triggerReason,
                                    'continuous_fail_count' => $continuousFailCount,
                                    'long_term_days' => $longTermDays,
                                    'price_top_multiple' => $priceTopMultiple,
                                ], JSON_UNESCAPED_UNICODE),
                                'create_time' => $now,
                                'update_time' => $now,
                            ]);

                            Db::commit();
                            $successCount++;
                            
                            $output->writeln("✓ 藏品 ID:{$collection['id']} 已转为矿机，用户ID：{$collection['user_id']}，原因：{$triggerReason}");
                        }

                    } catch (\Exception $e) {
                        Db::rollback();
                        $errorCount++;
                        $output->writeln("✗ 藏品 ID:{$collection['id']} 处理失败：" . $e->getMessage());
                        \think\facade\Log::error('藏品转为矿机处理失败', [
                            'collection_id' => $collection['id'] ?? 0,
                            'error_message' => $e->getMessage(),
                            'error_file' => $e->getFile(),
                            'error_line' => $e->getLine(),
                        ]);
                    }
                }

                if (count($collections) < $pageSize) {
                    $hasMore = false;
                } else {
                    $page++;
                }
            }

            $endTime = microtime(true);
            $executionTime = round($endTime - $startTime, 2);

            $output->writeln("\n========================================");
            $output->writeln("检查完成！");
            $output->writeln("执行耗时：{$executionTime} 秒");
            $output->writeln("总计检查：{$processCount} 个藏品");
            $output->writeln("转为矿机：{$successCount} 个");
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
            
            \think\facade\Log::error('藏品转为矿机脚本异常', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'execution_time' => $executionTime,
            ]);
            
            return 1;
        }
    }
}

