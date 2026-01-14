<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 矿机每日分红定时任务
 * 用于自动发放矿机每日分红（一半余额，一半消费金）
 * 
 * 使用方法：
 * php think collection:mining:dividend
 * 
 * Crontab 配置示例（每天凌晨6点执行）：
 * 0 6 * * * cd /www/wwwroot/18.166.209.223 && php think collection:mining:dividend >> /tmp/collection_mining_dividend.log 2>&1
 */
class CollectionMiningDividend extends Command
{
    protected function configure()
    {
        $this->setName('collection:mining:dividend')
            ->setDescription('矿机每日分红发放');
    }

    protected function execute(Input $input, Output $output)
    {
        $startTime = microtime(true);
        $output->writeln('========================================');
        $output->writeln('开始发放矿机每日分红...');
        $output->writeln('执行时间：' . date('Y-m-d H:i:s'));
        $output->writeln('========================================');
        
        // 从系统配置读取分红参数
        $dailyDividendAmount = (float)get_sys_config('mining_daily_dividend', 0);
        $dividendBalanceRate = (float)get_sys_config('mining_dividend_balance', 0.5);
        $dividendScoreRate = (float)get_sys_config('mining_dividend_score', 0.5);
        $dividendPriceRate = (float)get_sys_config('mining_dividend_price_rate', 0);
        
        // 检查分红配置：如果价格比例为0，则检查固定金额；如果价格比例大于0，则允许分红
        if ($dividendPriceRate <= 0 && $dailyDividendAmount <= 0) {
            $output->writeln("⚠ 警告：分红价格比例为0且每日分红金额为0或未配置，跳过发放");
            return 0;
        }
        
        // 确保比例在有效范围内
        if ($dividendBalanceRate < 0 || $dividendBalanceRate > 1) {
            $dividendBalanceRate = 0.5;
        }
        if ($dividendScoreRate < 0 || $dividendScoreRate > 1) {
            $dividendScoreRate = 0.5;
        }
        
        $output->writeln("配置参数：");
        if ($dividendPriceRate > 0) {
            $output->writeln("  分红价格比例：{$dividendPriceRate}（" . ($dividendPriceRate * 100) . "%）");
            $output->writeln("  余额分配比例：{$dividendBalanceRate}（" . ($dividendBalanceRate * 100) . "%）");
            $output->writeln("  积分分配比例：{$dividendScoreRate}（" . ($dividendScoreRate * 100) . "%）");
        } else {
            $output->writeln("  每日分红金额：{$dailyDividendAmount} 元");
            $output->writeln("  余额分配比例：{$dividendBalanceRate}（" . ($dividendBalanceRate * 100) . "%）");
            $output->writeln("  积分分配比例：{$dividendScoreRate}（" . ($dividendScoreRate * 100) . "%）");
        }
        
        $now = time();
        $todayStart = strtotime(date('Y-m-d 00:00:00')); // 今天0点
        
        $processCount = 0;
        $successCount = 0;
        $errorCount = 0;
        $skipCount = 0;

        try {
            // 查询所有矿机状态的藏品（mining_status = 1）
            $pageSize = 100;
            $page = 1;
            $hasMore = true;

            while ($hasMore) {
                $collections = Db::name('user_collection')
                    ->where('mining_status', 1) // 矿机状态
                    ->order('id', 'asc')
                    ->page($page, $pageSize)
                    ->select()
                    ->toArray();

                if (empty($collections)) {
                    $hasMore = false;
                    break;
                }

                $processCount += count($collections);
                $output->writeln("第 {$page} 页：处理 " . count($collections) . " 个矿机");

                foreach ($collections as $collection) {
                    try {
                        // 检查今天是否已经发放过分红
                        $lastDividendTime = (int)$collection['last_dividend_time'];
                        if ($lastDividendTime >= $todayStart) {
                            $skipCount++;
                            continue;
                        }

                        // 双重检查：再次确认状态，避免并发问题
                        $currentCollection = Db::name('user_collection')
                            ->where('id', $collection['id'])
                            ->where('mining_status', 1)
                            ->lock(true)
                            ->find();

                        if (!$currentCollection) {
                            $skipCount++;
                            continue;
                        }

                        // 再次检查今天是否已发放
                        if ((int)$currentCollection['last_dividend_time'] >= $todayStart) {
                            $skipCount++;
                            continue;
                        }

                        Db::startTrans();

                        // 获取用户信息并锁定
                        $user = Db::name('user')
                            ->where('id', $collection['user_id'])
                            ->lock(true)
                            ->find();

                        if (!$user) {
                            throw new \Exception('用户不存在');
                        }

                        // 计算分红金额：按价格比例或固定金额
                        if ($dividendPriceRate > 0) {
                            // 按藏品当前价格的比例计算分红
                            $collectionPrice = (float)$collection['price'];
                            $actualDividendAmount = round($collectionPrice * $dividendPriceRate, 2);
                        } else {
                            // 使用固定金额分红
                            $actualDividendAmount = $dailyDividendAmount;
                        }

                        // 计算分红分配
                        $dividendToBalance = round($actualDividendAmount * $dividendBalanceRate, 2);
                        $dividendToScore = (int)round($actualDividendAmount * $dividendScoreRate);

                        // 更新用户余额和消费金（分红收益进入withdrawable_money可提现余额）
                        $beforeWithdrawable = (float)$user['withdrawable_money'];
                        $beforeScore = (float)$user['score'];
                        $afterWithdrawable = $beforeWithdrawable + $dividendToBalance;
                        $afterScore = $beforeScore + $dividendToScore;

                        Db::name('user')
                            ->where('id', $collection['user_id'])
                            ->update([
                                'withdrawable_money' => $afterWithdrawable,
                                'score' => $afterScore,
                                'update_time' => $now,
                            ]);

                        // 更新矿机最后分红时间
                        Db::name('user_collection')
                            ->where('id', $collection['id'])
                            ->where('mining_status', 1) // 再次确认状态
                            ->update([
                                'last_dividend_time' => $now,
                                'update_time' => $now,
                            ]);

                        // 记录余额变动日志（记录withdrawable_money的变化）
                        Db::name('user_money_log')->insert([
                            'user_id' => $collection['user_id'],
                            'money' => $dividendToBalance,
                            'before' => $beforeWithdrawable,
                            'after' => $afterWithdrawable,
                            'memo' => '矿机每日分红（可提现余额）：' . $collection['title'],
                            'create_time' => $now,
                        ]);

                        // 如果有积分变动，记录积分日志
                        if ($dividendToScore > 0) {
                            Db::name('user_score_log')->insert([
                                'user_id' => $collection['user_id'],
                                'score' => $dividendToScore,
                                'before' => $beforeScore,
                                'after' => $afterScore,
                                'memo' => '矿机每日分红（消费金）：' . $collection['title'],
                                'create_time' => $now,
                            ]);
                        }

                        // 记录活动日志
                        Db::name('user_activity_log')->insert([
                            'user_id' => $collection['user_id'],
                            'related_user_id' => 0,
                            'action_type' => 'mining_dividend',
                            'change_field' => 'withdrawable_money,score',
                            'change_value' => json_encode([
                                'withdrawable_money' => $dividendToBalance,
                                'score' => $dividendToScore,
                            ]),
                            'before_value' => json_encode([
                                'withdrawable_money' => $beforeWithdrawable,
                                'score' => $beforeScore,
                            ]),
                            'after_value' => json_encode([
                                'withdrawable_money' => $afterWithdrawable,
                                'score' => $afterScore,
                            ]),
                            'remark' => '矿机每日分红：' . $collection['title'] . '（可提现余额：' . number_format($dividendToBalance, 2) . '元，消费金：' . $dividendToScore . '分）',
                            'extra' => json_encode([
                                'user_collection_id' => $collection['id'],
                                'item_id' => $collection['item_id'],
                                'item_title' => $collection['title'],
                                'collection_price' => (float)$collection['price'],
                                'dividend_price_rate' => $dividendPriceRate,
                                'fixed_dividend_amount' => $dailyDividendAmount,
                                'actual_dividend_amount' => $actualDividendAmount,
                                'dividend_balance' => $dividendToBalance,
                                'dividend_score' => $dividendToScore,
                                'dividend_balance_rate' => $dividendBalanceRate,
                                'dividend_score_rate' => $dividendScoreRate,
                            ], JSON_UNESCAPED_UNICODE),
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);

                        Db::commit();
                        $successCount++;
                        
                        if ($dividendPriceRate > 0) {
                            $output->writeln("✓ 矿机 ID:{$collection['id']} 分红发放成功，用户ID：{$collection['user_id']}，藏品价格：{$collection['price']}元，分红：{$actualDividendAmount}元（余额：{$dividendToBalance}元，消费金：{$dividendToScore}分）");
                        } else {
                            $output->writeln("✓ 矿机 ID:{$collection['id']} 分红发放成功，用户ID：{$collection['user_id']}，余额：{$dividendToBalance}元，消费金：{$dividendToScore}分");
                        }

                    } catch (\Exception $e) {
                        Db::rollback();
                        $errorCount++;
                        $output->writeln("✗ 矿机 ID:{$collection['id']} 分红发放失败：" . $e->getMessage());
                        \think\facade\Log::error('矿机分红发放失败', [
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
            $output->writeln("分红发放完成！");
            $output->writeln("执行耗时：{$executionTime} 秒");
            $output->writeln("总计处理：{$processCount} 个矿机");
            $output->writeln("成功发放：{$successCount} 个");
            $output->writeln("跳过记录：{$skipCount} 个（今日已发放）");
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
            
            \think\facade\Log::error('矿机分红脚本异常', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'execution_time' => $executionTime,
            ]);
            
            return 1;
        }
    }
}

