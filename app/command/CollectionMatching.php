<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use app\common\service\UserService;
use app\common\service\ConsignmentService;

/**
 * 藏品撮合定时任务
 * 用于自动撮合撮合池中的竞价购买记录
 * 
 * 撮合规则：
 * 1. 只在专场时间结束后才开始撮合
 * 2. 按权重从高到低排序
 * 3. 权重相同时，按时间从早到晚排序
 * 4. 使用轮盘赌机制决定中签
 * 5. 未中签：退回本金，销毁算力
 * 6. 中签：卖家获得本金+50%利润（50%到可调度收益，50%到消费金），买家获得数字资产
 * 
 * 使用方法：
 * php think collection:matching
 * 
 * Crontab 配置示例（每分钟执行一次）：
 * * * * * * cd /www/wwwroot/18.166.209.223 && php think collection:matching >> /tmp/collection_matching.log 2>&1
 */
class CollectionMatching extends Command
{
    protected function configure()
    {
        $this->setName('collection:matching')
            ->setDescription('藏品撮合池自动撮合（轮盘赌机制）')
            ->addOption('force', null, \think\console\input\Option::VALUE_NONE, '强制撮合（忽略场次未结束的时间限制）')
            ->addOption('timestamp', null, \think\console\input\Option::VALUE_REQUIRED, '指定时间戳（Unix时间戳），用于测试特定时间点的撮合逻辑');
    }

    /**
     * 判断时间是否在范围内（支持跨天）
     */
    private function isTimeInRange(string $currentTime, string $startTime, string $endTime): bool
    {
        // 如果结束时间小于开始时间，说明跨天
        if ($endTime < $startTime) {
            return $currentTime >= $startTime || $currentTime <= $endTime;
        }
        return $currentTime >= $startTime && $currentTime <= $endTime;
    }

    /**
     * 轮盘赌算法：根据权重计算中签
     * @param array $records 待撮合记录数组，每个记录包含 weight 字段
     * @param int $stock 库存数量（中签数量）
     * @return array 中签的记录ID数组
     */
    private function rouletteWheel(array $records, int $stock): array
    {
        if (empty($records) || $stock <= 0) {
            return [];
        }

        // 如果库存大于等于记录数，全部中签
        if ($stock >= count($records)) {
            return array_column($records, 'id');
        }

        // 计算总权重
        $totalWeight = 0;
        foreach ($records as $record) {
            $totalWeight += (int)$record['weight'];
        }

        if ($totalWeight <= 0) {
            // 如果总权重为0，随机选择
            $selected = array_rand($records, min($stock, count($records)));
            return is_array($selected) ? array_map(function($idx) use ($records) {
                return $records[$idx]['id'];
            }, $selected) : [$records[$selected]['id']];
        }

        // 轮盘赌选择
        $selectedIds = [];
        $selectedIndexes = [];

        for ($i = 0; $i < $stock; $i++) {
            // 重新计算剩余权重（排除已选中的）
            $remainingWeight = 0;
            $remainingRecords = [];
            foreach ($records as $idx => $record) {
                if (!in_array($idx, $selectedIndexes)) {
                    $remainingWeight += (int)$record['weight'];
                    $remainingRecords[] = ['idx' => $idx, 'weight' => (int)$record['weight']];
                }
            }

            if (empty($remainingRecords)) {
                break;
            }

            // 生成随机数
            $random = mt_rand(1, $remainingWeight);
            $cumulativeWeight = 0;

            foreach ($remainingRecords as $item) {
                $cumulativeWeight += $item['weight'];
                if ($random <= $cumulativeWeight) {
                    $selectedIdx = $item['idx'];
                    $selectedIds[] = $records[$selectedIdx]['id'];
                    $selectedIndexes[] = $selectedIdx;
                    break;
                }
            }
        }

        return $selectedIds;
    }

    /**
     * 进程锁文件句柄
     */
    private $lockFileHandle = null;
    
    /**
     * 进程锁文件路径
     */
    private $lockFilePath = null;

    protected function execute(Input $input, Output $output)
    {
        $startTime = microtime(true);
        
        // ========== 进程锁：防止并发执行 ==========
        $this->lockFilePath = runtime_path() . 'lock/collection_matching.lock';
        $lockDir = dirname($this->lockFilePath);
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }
        
        $this->lockFileHandle = fopen($this->lockFilePath, 'w');
        if (!$this->lockFileHandle) {
            $output->writeln('<error>[' . date('Y-m-d H:i:s') . '] 无法创建锁文件，退出</error>');
            return 1;
        }
        
        // 尝试获取独占锁（非阻塞）
        if (!flock($this->lockFileHandle, LOCK_EX | LOCK_NB)) {
            $output->writeln('[' . date('Y-m-d H:i:s') . '] ⚠️ 撮合脚本已在运行中，本次跳过执行');
            fclose($this->lockFileHandle);
            return 0;
        }
        
        // 写入进程信息
        ftruncate($this->lockFileHandle, 0);
        fwrite($this->lockFileHandle, json_encode([
            'pid' => getmypid(),
            'start_time' => date('Y-m-d H:i:s'),
            'timestamp' => time(),
        ]));
        fflush($this->lockFileHandle);
        // ========== 进程锁结束 ==========
        
        // 检测运行模式
        $isForceMode = (bool)$input->getOption('force') || getenv('FORCE_MATCHING') === '1';
        $isCronMode = !posix_isatty(STDOUT); // 检测是否在终端运行（非终端 = 定时任务）
        
        $runMode = $isForceMode ? '强制撮合' : ($isCronMode ? '自动运行' : '手动运行');
        $runModeSymbol = $isForceMode ? '⚡' : ($isCronMode ? '🤖' : '👤');
        
        $output->writeln('================================================================================');
        $output->writeln('[' . date('Y-m-d H:i:s') . "] {$runModeSymbol} 开始处理撮合池撮合（轮盘赌机制）- {$runMode}");
        $output->writeln('================================================================================');
        
        // 支持指定时间戳，用于测试特定时间点的撮合逻辑
        $specifiedTimestamp = $input->getOption('timestamp');
        if ($specifiedTimestamp && is_numeric($specifiedTimestamp)) {
            $now = (int)$specifiedTimestamp;
            $currentTime = date('H:i', $now);
            $output->writeln("  📅 使用指定时间戳: {$now} (" . date('Y-m-d H:i:s', $now) . ")");
        } else {
            $now = time();
            $currentTime = date('H:i');
        }
        $processCount = 0;
        $successCount = 0;
        $errorCount = 0;
        $refundCount = 0;

        try {
            // (已移动到最后执行) 首先处理场次结束后自动下架寄售订单
            // 按专场分组，只处理专场时间已结束的
            // 获取所有有pending记录的专场（包括商品下架但有寄售记录的情况）
            $sessionIds = Db::name('collection_matching_pool')
                ->alias('mp')
                ->leftJoin('collection_item ci', 'mp.item_id = ci.id')
                ->leftJoin('collection_session cs', 'ci.session_id = cs.id')
                ->where('mp.status', 'pending')
                ->where('cs.status', '1')
                ->field('DISTINCT cs.id')
                ->select()
                ->column('id');

            $sessions = [];
            foreach ($sessionIds as $sessionId) {
                $session = Db::name('collection_session')
                    ->where('id', $sessionId)
                    ->field('id as session_id, title as session_title, start_time, end_time')
                    ->find();

                if ($session) {
                    $sessions[] = $session;
                }
            }

            foreach ($sessions as $session) {
                $sessionId = (int)$session['session_id'];
                $startTimeStr = $session['start_time'] ?? '';
                $endTimeStr = $session['end_time'] ?? '';
                
                // 检查专场时间是否已结束
                if (empty($startTimeStr) || empty($endTimeStr)) {
                    continue;
                }

                // 判断当前时间是否在交易时间内
                $isInTradingTime = $this->isTimeInRange($currentTime, $startTimeStr, $endTimeStr);
                
                // 支持临时强制撮合，用于测试：设置环境变量 FORCE_MATCHING=1 可忽略时间检查
                $forceMatching = getenv('FORCE_MATCHING') === '1' || (bool)$input->getOption('force');
                if ($forceMatching) {
                    $output->writeln("  !! 强制撮合模式启用（忽略交易时间检查）");
                    $isInTradingTime = false;
                }

                if ($isInTradingTime) {
                    $output->writeln("  专场【{$session['session_title']}】交易时间未结束（{$startTimeStr} - {$endTimeStr}），跳过撮合");
                    continue;
                }

                $output->writeln("  开始处理专场【{$session['session_title']}】的撮合...");

                // 🆕 统计参与人数
                $participantCount = Db::name('collection_matching_pool')
                    ->where('session_id', $sessionId)
                    ->count('DISTINCT user_id');
                $output->writeln("  👥 参与人数：{$participantCount}");

                // 🆕 统计资产包藏品数量
                $packageStats = Db::name('collection_item')
                    ->alias('ci')
                    ->leftJoin('asset_package cp', 'ci.package_id = cp.id')
                    ->where('ci.session_id', $sessionId)
                    ->field('cp.name as package_name, count(ci.id) as item_count, sum(ci.stock) as total_stock')
                    ->group('ci.package_id')
                    ->select();
                
                if (!empty($packageStats)) {
                    foreach ($packageStats as $stat) {
                        $packageName = $stat['package_name'] ?: '未分组';
                        $output->writeln("  📦 资产包【{$packageName}】：藏品数量 {$stat['item_count']}，库存总量 {$stat['total_stock']}");
                    }
                }

                // 按藏品分组，逐个处理
                // 获取有pending记录的商品（包括商城上架商品和寄售商品）
                $pendingItems = Db::name('collection_matching_pool')
                    ->where('status', 'pending')
                    ->where('session_id', $sessionId)
                    ->field('item_id, COUNT(id) as pool_count')
                    ->group('item_id')
                    ->select()
                    ->toArray();

                // 🆕 统计每个卖家在该场次的寄售数量（多次寄售优先）
                $sellerConsignmentCounts = Db::name('collection_consignment')
                    ->alias('cc')
                    ->leftJoin('collection_item ci', 'cc.item_id = ci.id')
                    ->where('ci.session_id', $sessionId)
                    ->where('cc.status', 1) // 寄售中
                    ->field('cc.user_id, COUNT(*) as consign_count')
                    ->group('cc.user_id')
                    ->select()
                    ->column('consign_count', 'user_id');

                $items = [];
                foreach ($pendingItems as $pendingItem) {
                    $itemId = (int)$pendingItem['item_id'];

                    // 检查商品信息
                    $itemInfo = Db::name('collection_item')
                        ->where('id', $itemId)
                        ->where('session_id', $sessionId)
                        ->find();

                    if (!$itemInfo) {
                        continue;
                    }

                    // 检查是否有寄售记录（状态为寄售中）
                    $consignmentInfo = Db::name('collection_consignment')
                        ->where('item_id', $itemId)
                        ->where('status', 1) // 寄售中
                        ->find();
                    
                    $hasActiveConsignment = !empty($consignmentInfo);

                    // 如果商品上架且有库存，或者有寄售记录，则可以撮合
                    $canMatch = false;
                    $stock = 0;
                    $sellerId = 0;
                    $sellerConsignCount = 0;

                    if ((int)$itemInfo['status'] === 1 && (int)$itemInfo['stock'] > 0) {
                        // 商城上架商品
                        $canMatch = true;
                        $stock = (int)$itemInfo['stock'];
                    } elseif ($hasActiveConsignment) {
                        // 寄售商品，设置虚拟库存为1（因为寄售商品只有一个）
                        $canMatch = true;
                        $stock = 1;
                        $sellerId = (int)$consignmentInfo['user_id'];
                        $sellerConsignCount = $sellerConsignmentCounts[$sellerId] ?? 1;
                    }

                    if ($canMatch) {
                        $items[] = [
                            'item_id' => $itemId,
                            'stock' => $stock,
                            'pool_count' => (int)$pendingItem['pool_count'],
                            'is_consignment' => $hasActiveConsignment,
                            'seller_id' => $sellerId,
                            'seller_consign_count' => $sellerConsignCount // 🆕 卖家寄售数量
                        ];
                    }
                }

                if (empty($items)) {
                    $output->writeln("  专场【{$session['session_title']}】没有满足条件的商品（需上架且有库存），跳过处理");
                    continue;
                }

                // 🆕 对商品排序：寄售商品按卖家寄售数量降序（多次寄售优先）
                usort($items, function($a, $b) {
                    // 1. 寄售商品优先于商城商品
                    if ($a['is_consignment'] != $b['is_consignment']) {
                        return $b['is_consignment'] - $a['is_consignment'];
                    }
                    // 2. 寄售商品按卖家寄售数量降序（多次寄售优先）
                    if ($a['is_consignment'] && $b['is_consignment']) {
                        return $b['seller_consign_count'] - $a['seller_consign_count'];
                    }
                    // 3. 商城商品按申购人数降序
                    return $b['pool_count'] - $a['pool_count'];
                });

                // 🆕 输出多次寄售卖家统计
                $multiConsignSellers = array_filter($sellerConsignmentCounts, function($count) { return $count > 1; });
                if (!empty($multiConsignSellers)) {
                    $output->writeln("  📦 多次寄售卖家：" . count($multiConsignSellers) . " 人（商品优先撮合）");
                }

                $output->writeln("  找到 " . count($items) . " 个可处理商品");

                foreach ($items as $item) {
                    $itemId = (int)$item['item_id'];
                    $stock = (int)$item['stock'];
                    $poolCount = (int)$item['pool_count'];
                    
                    if ($poolCount <= 0) {
                        continue;
                    }

                    // 🆕 统计该场次中每个用户的申购次数（多次申购优先）
                    $userApplicationCounts = Db::name('collection_matching_pool')
                        ->where('session_id', $sessionId)
                        ->where('status', 'pending')
                        ->field('user_id, COUNT(*) as apply_count')
                        ->group('user_id')
                        ->select()
                        ->column('apply_count', 'user_id');

                    // 查询该藏品所有待撮合的记录
                    $pendingRecords = Db::name('collection_matching_pool')
                        ->where('item_id', $itemId)
                        ->where('status', 'pending')
                        ->select()
                        ->toArray();

                    if (empty($pendingRecords)) {
                        continue;
                    }

                    // 🆕 为每条记录添加用户申购次数，用于排序
                    foreach ($pendingRecords as &$record) {
                        $record['user_apply_count'] = $userApplicationCounts[$record['user_id']] ?? 1;
                    }
                    unset($record);

                    // 🆕 新排序规则：申购次数降序 > 权重降序 > 时间升序
                    usort($pendingRecords, function($a, $b) {
                        // 1. 先按用户申购次数降序（多次申购优先）
                        if ($a['user_apply_count'] != $b['user_apply_count']) {
                            return $b['user_apply_count'] - $a['user_apply_count'];
                        }
                        // 2. 再按权重降序
                        if ($a['weight'] != $b['weight']) {
                            return $b['weight'] - $a['weight'];
                        }
                        // 3. 最后按时间升序（早申购优先）
                        return $a['create_time'] - $b['create_time'];
                    });

                    // 决定中签策略
                    $needCount = min($stock, $poolCount);
                    
                    // 🆕 检查是否所有候选的申购次数和权重都相同
                    $applyCountsForCheck = array_column($pendingRecords, 'user_apply_count');
                    $weightsForCheck = array_column($pendingRecords, 'weight');
                    $distinctApplyCounts = array_unique($applyCountsForCheck);
                    $distinctWeights = array_unique($weightsForCheck);
                    
                    if (count($distinctApplyCounts) === 1 && count($distinctWeights) === 1) {
                        // 全部申购次数和权重相同，读取配置决定平局处理方式
                        $tieMode = (string)(get_sys_config('matching_tie_breaker', 'time') ?? 'time'); // 'time' 或 'random'
                        if ($tieMode === 'time') {
                            // 已排序，直接取前 N 条（时间早的优先）
                            $selectedSlice = array_slice($pendingRecords, 0, $needCount);
                            $selectedIds = array_column($selectedSlice, 'id');
                        } else {
                            // 随机选择
                            $rand = array_rand($pendingRecords, min($needCount, count($pendingRecords)));
                            if (is_array($rand)) {
                                $selectedIds = array_map(function($idx) use ($pendingRecords) {
                                    return $pendingRecords[$idx]['id'];
                                }, $rand);
                            } else {
                                $selectedIds = [$pendingRecords[$rand]['id']];
                            }
                        }
                    } else {
                        // 🆕 存在不同申购次数或权重，按排序顺序优先选择（多次申购用户优先）
                        // 直接取前 N 条（已按 申购次数降序 > 权重降序 > 时间升序 排序）
                        $selectedSlice = array_slice($pendingRecords, 0, $needCount);
                        $selectedIds = array_column($selectedSlice, 'id');
                    }
                    $selectedIdsMap = array_flip($selectedIds);
                    
                    // 🆕 输出多次申购用户统计
                    $multiApplyUsers = array_filter($userApplicationCounts, function($count) { return $count > 1; });
                    if (!empty($multiApplyUsers)) {
                        $output->writeln("    📊 多次申购用户：" . count($multiApplyUsers) . " 人（优先撮合）");
                    }

                    $itemType = isset($item['is_consignment']) && $item['is_consignment'] ? '寄售商品' : '商城商品';
                    $output->writeln("  {$itemType} ID {$itemId}：总记录数 {$poolCount}，库存 {$stock}，中签数 " . count($selectedIds));

                    // 处理每条记录
                    foreach ($pendingRecords as $record) {
                        $processCount++;
                        $recordId = (int)$record['id'];
                        $isSelected = isset($selectedIdsMap[$recordId]);
                        
                        try {
                            Db::startTrans();
                            
                            // 重新检查记录状态（防止并发问题）
                            $recordInfo = Db::name('collection_matching_pool')
                                ->where('id', $recordId)
                                ->where('status', 'pending')
                                ->lock(true)
                                ->find();
                            
                            if (!$recordInfo) {
                                Db::rollback();
                                continue;
                            }

                            $userId = (int)$recordInfo['user_id'];
                            $powerUsed = (float)$recordInfo['power_used'];
                            
                            // 获取用户信息
                            $user = Db::name('user')
                                ->where('id', $userId)
                                ->lock(true)
                                ->find();
                            
                            if (!$user) {
                                Db::rollback();
                                $errorCount++;
                                $output->writeln("    用户ID {$userId} 不存在，跳过");
                                continue;
                            }

                            // 获取藏品信息（寄售商品也需要检查基本信息）
                            $itemInfo = Db::name('collection_item')
                                ->where('id', $itemId)
                                ->lock(true)
                                ->find();

                            if (!$itemInfo) {
                                Db::rollback();
                                $errorCount++;
                                $output->writeln("    藏品ID {$itemId} 不存在，跳过");
                                continue;
                            }

                            $itemPrice = (float)$itemInfo['price'];

                            // 检查是否为寄售商品
                            $isConsignmentItem = isset($item['is_consignment']) && $item['is_consignment'];

                            if ($isSelected) {
                                // 中签：交易完成

                                if ($isConsignmentItem) {
                                    // 寄售商品：检查寄售记录状态
                                    $consignment = Db::name('collection_consignment')
                                        ->where('item_id', $itemId)
                                        ->where('status', 1) // 寄售中
                                        ->lock(true)
                                        ->find();

                                    if (!$consignment) {
                                        Db::rollback();
                                        $errorCount++;
                                        $output->writeln("    藏品ID {$itemId} 寄售记录不存在或状态异常，跳过中签处理");
                                        continue;
                                    }
                                } else {
                                    // 商城商品：检查库存
                                    if ((int)$itemInfo['status'] !== 1 || (int)$itemInfo['stock'] <= 0) {
                                        Db::rollback();
                                        $errorCount++;
                                        $output->writeln("    藏品ID {$itemId} 已下架或库存不足，跳过中签处理");
                                        continue;
                                    }
                                }

                                // 优先检查是否存在预约冻结（trade_reservations），若存在且冻结金额足够则直接使用冻结资金
                                $usedReservation = false;
                                $reservation = Db::name('trade_reservations')
                                    ->where('user_id', $userId)
                                    ->where('session_id', $sessionId)
                                    ->where('status', 0) // pending
                                    ->lock(true)
                                    ->find();

                                if ($reservation) {
                                    $freezeAmt = (float)$reservation['freeze_amount'];
                                    if ($freezeAmt >= $itemPrice) {
                                        // 标记预约为已使用
                                        Db::name('trade_reservations')->where('id', $reservation['id'])->update([
                                            'status' => 1,
                                            'update_time' => $now,
                                        ]);
                                        $usedReservation = true;
                                        // 记录活动日志，表示冻结资金被消费
                                        Db::name('user_activity_log')->insert([
                                            'user_id' => $userId,
                                            'action_type' => 'reserve_used',
                                            'change_field' => 'freeze_amount',
                                            'change_value' => json_encode(['freeze_amount' => -$itemPrice], JSON_UNESCAPED_UNICODE),
                                            'before_value' => json_encode(['freeze_amount' => $freezeAmt], JSON_UNESCAPED_UNICODE),
                                            'after_value' => json_encode(['freeze_amount' => $freezeAmt - $itemPrice], JSON_UNESCAPED_UNICODE),
                                            'remark' => sprintf('使用预约冻结资金支付订单：%.2f', $itemPrice),
                                            'extra' => json_encode(['item_id' => $itemId, 'order_price' => $itemPrice], JSON_UNESCAPED_UNICODE),
                                            'create_time' => $now,
                                        ]);
                                    }
                                }

                                if (!$usedReservation) {
                                    // 检查用户余额：使用用户可用余额（专项金）作为支付来源
                                    if ($user['balance_available'] < $itemPrice) {
                                        Db::rollback();
                                        $errorCount++;
                                        $output->writeln("    用户ID {$userId} 专项金不足，跳过中签处理");
                                        continue;
                                    }

                                    // 扣除用户余额（只扣除真实余额池balance_available，money是派生值会自动计算）
                                    $beforeBalance = (float)$user['balance_available'];
                                    $afterBalance = $beforeBalance - $itemPrice;

                                    Db::name('user')->where('id', $userId)->update([
                                        'balance_available' => $afterBalance,
                                        'update_time' => $now,
                                    ]);

                                    // 记录余额日志（记录balance_available的变动）
                                    $flowNo = generateSJSFlowNo($userId);
                                    $batchNo = generateBatchNo('MATCHING_BUY', $reservationId);
                                    Db::name('user_money_log')->insert([
                                        'user_id' => $userId,
                                        'flow_no' => $flowNo,
                                        'batch_no' => $batchNo,
                                        'biz_type' => 'matching_buy',
                                        'biz_id' => $reservationId,
                                        'field_type' => 'balance_available', // 可用余额变动
                                        'money' => -$itemPrice,
                                        'before' => $beforeBalance,
                                        'after' => $afterBalance,
                                        'memo' => '撮合购买藏品（中签）：' . $itemInfo['title'],
                                        'create_time' => $now,
                                    ]);
                                }

                                // 创建订单
                                $orderNo = 'CO' . date('YmdHis') . str_pad($userId, 6, '0', STR_PAD_LEFT) . mt_rand(1000, 9999);
                                
                                $orderData = [
                                    'order_no' => $orderNo,
                                    'user_id' => $userId,
                                    'total_amount' => $itemPrice,
                                    'pay_type' => 'money',
                                    'status' => 'paid',
                                    'pay_time' => $now,
                                    'complete_time' => $now,
                                    'create_time' => $now,
                                    'update_time' => $now,
                                ];

                                $orderId = Db::name('collection_order')->insertGetId($orderData);

                                if (!$orderId) {
                                    Db::rollback();
                                    $errorCount++;
                                    $output->writeln("    创建订单失败，用户ID: {$userId}, 藏品ID: {$itemId}");
                                    continue;
                                }

                                // 创建订单明细
                                Db::name('collection_order_item')->insert([
                                    'order_id' => $orderId,
                                    'item_id' => $itemId,
                                    'item_title' => $itemInfo['title'],
                                    'item_image' => $itemInfo['image'],
                                    'price' => $itemPrice,
                                    'quantity' => 1,
                                    'subtotal' => $itemPrice,
                                    'create_time' => $now,
                                ]);

                                if ($isConsignmentItem) {
                                    // 寄售商品：更新寄售记录状态为已售出，并增加商品销量
                                    Db::name('collection_consignment')
                                        ->where('id', $consignment['id'])
                                        ->update([
                                            'status' => 2, // 已售出
                                            'sold_price' => $itemPrice, // 记录成交价
                                            'update_time' => $now
                                        ]);

                                    // 调用结算服务填充利润等字段
                                    $serviceFeeRate = (float)(get_sys_config('consignment_service_fee_rate') ?? 0.03);
                                    $sellerOriginalPrice = (float)($consignment['original_price'] ?? $itemPrice);
                                    $serviceFee = round($sellerOriginalPrice * $serviceFeeRate, 2);
                                    \app\common\service\ConsignmentService::updateConsignmentSettlement(
                                        $consignment['id'],
                                        $itemPrice, // 成交价
                                        $sellerOriginalPrice, // 原价
                                        $serviceFee, // 手续费
                                        true, // 手续费在申请时已扣
                                        [] // 让服务自动计算其他字段
                                    );

                                    // 更新卖家的持有记录状态为已售出
                                    Db::name('user_collection')
                                        ->where('id', $consignment['user_collection_id'])
                                        ->where('user_id', $consignment['user_id'])
                                        ->update([
                                            'consignment_status' => 2, // 已售出
                                            'update_time' => $now
                                        ]);

                                    // 增加商品销量
                                    Db::name('collection_item')
                                        ->where('id', $itemId)
                                        ->inc('sales', 1)
                                        ->update(['update_time' => $now]);

                                    $output->writeln("    寄售商品 {$itemId} 已售出，寄售记录ID: {$consignment['id']}");
                                } else {
                                    // 商城商品：扣减库存，增加销量
                                    Db::name('collection_item')
                                        ->where('id', $itemId)
                                        ->dec('stock', 1)
                                        ->inc('sales', 1)
                                        ->update(['update_time' => $now]);
                                    // 自动下架：若库存降为 0 或更小，设置商品状态为下架（status = 0）
                                    $newStock = (int)Db::name('collection_item')->where('id', $itemId)->value('stock');
                                    if ($newStock <= 0) {
                                        Db::name('collection_item')->where('id', $itemId)->update(['status' => '0', 'update_time' => $now]);
                                        $output->writeln("    藏品ID {$itemId} 库存为 {$newStock}，已自动下架（status=0）");
                                    }
                                }

                                // 创建用户藏品记录（买家获得数字资产），使用原价格作为买入价格
                                // 这样卖家寄售时可以按新价格挂单
                                Db::name('user_collection')->insert([
                                    'user_id'           => $userId,
                                    'order_id'          => $orderId,
                                    'order_item_id'     => 0,
                                    'item_id'           => $itemId,
                                    'title'             => $itemInfo['title'] ?? '',
                                    'image'             => $itemInfo['image'] ?? '',
                                    'price'             => $itemPrice, // 🆕 使用实际购买价格作为成本（修复利润计算问题）
                                    'buy_time'          => $now,
                                    'delivery_status'   => 0,
                                    'consignment_status'=> 0,
                                    'auto_relist_next_day' => (int)(get_sys_config('auto_relist_default', 0)),
                                    // 🆕 填充确权元数据（从藏品模板继承）
                                    'contract_no'       => $itemInfo['contract_no'] ?? null,
                                    'rights_status'     => $itemInfo['rights_status'] ?? null,
                                    'block_height'      => $itemInfo['block_height'] ?? null,
                                    'rights_hash'       => $itemInfo['rights_hash'] ?? null,
                                    'create_time'       => $now,
                                    'update_time'       => $now,
                                ]);
                                
                                $output->writeln("    📈 价格增值：{$itemPrice} → {$newItemPrice} (+" . round($priceIncrementRate * 100) . "%)");

                                // 处理卖家收益分配
                                // 🆕 新增值逻辑：利润 = 售价 - 卖家原购买价格
                                $profitBalanceRate = (float)(get_sys_config('matching_profit_balance') ?? 0.5);
                                $profitScoreRate = (float)(get_sys_config('matching_profit_score') ?? 0.5);
                                // 验证比例合法性
                                if ($profitBalanceRate < 0 || $profitBalanceRate > 1) {
                                    $profitBalanceRate = 0.5;
                                }
                                if ($profitScoreRate < 0 || $profitScoreRate > 1) {
                                    $profitScoreRate = 0.5;
                                }
                                // 规范两者之和为1（若不等于1，按 balance 优先，score = 1 - balance）
                                if (abs(($profitBalanceRate + $profitScoreRate) - 1.0) > 0.0001) {
                                    $profitScoreRate = 1.0 - $profitBalanceRate;
                                }

                                // 查找卖家
                                if ($isConsignmentItem) {
                                    // 寄售商品：使用前面找到的寄售记录
                                    $sellerConsignment = $consignment;
                                    $distributeToSeller = true; // 寄售商品必须分配给卖家
                                } else {
                                    // 商城商品：查找寄售记录，如果没有则卖家是平台
                                    $sellerConsignment = Db::name('collection_consignment')
                                        ->where('item_id', $itemId)
                                        ->where('status', 2) // 已售出
                                        ->order('update_time desc')
                                        ->find();

                                    // 判断是否按配置给寄售卖家分配收益（默认分配）
                                    $distributeToSeller = (bool)(get_sys_config('matching_distribute_to_seller', 1) ?? 1);
                                }
                                if ($sellerConsignment && $distributeToSeller) {
                                    // 有寄售记录且配置允许分配，卖家是寄售用户
                                    $sellerId = (int)$sellerConsignment['user_id'];
                                    $seller = Db::name('user')
                                        ->where('id', $sellerId)
                                        ->lock(true)
                                        ->find();
                                    
                                    if ($seller) {
                                        // 🆕 获取卖家原购买价格（从寄售记录或user_collection获取）
                                        $sellerOriginalPrice = (float)($sellerConsignment['original_price'] ?? 0);
                                        $sellerUserCollection = null;
                                        if ($sellerOriginalPrice <= 0 || !isset($sellerUserCollection)) {
                                            // 如果寄售记录没有原价，从user_collection获取
                                            $sellerUserCollection = Db::name('user_collection')
                                                ->where('id', $sellerConsignment['user_collection_id'] ?? 0)
                                                ->find();
                                            if ($sellerOriginalPrice <= 0) {
                                                $sellerOriginalPrice = (float)($sellerUserCollection['price'] ?? $itemPrice);
                                            }
                                        }
                                        
                                        // 🆕 判断是否是旧资产包（旧资产包不返还手续费）
                                        $isOldAssetPackage = (int)($sellerUserCollection['is_old_asset_package'] ?? 0) === 1;
                                        
                                        // 🆕 利润 = 售价 - 原购买价格（增值差价）
                                        $profit = max(0, round($itemPrice - $sellerOriginalPrice, 2));
                                        
                                        // 🆕 新收益分配规则：
                                        // 1. 本金*3%的服务费金额直接到账提现余额（旧资产包不返还）
                                        // 2. 剩余利润（约2%）对半到账提现余额和确权金（service_fee_balance）
                                        
                                        $serviceFeeRate = (float)(get_sys_config('consignment_service_fee_rate') ?? 0.03);
                                        // 旧资产包不返还手续费
                                        $feeRefund = $isOldAssetPackage ? 0 : round($sellerOriginalPrice * $serviceFeeRate, 2);
                                        
                                        $remainingProfit = max(0, $profit - $feeRefund);
                                        
                                        // 剩余利润拆分（从配置读取）
                                        $splitRate = (float)(get_sys_config('seller_profit_split_rate') ?? 0.5);
                                        if ($splitRate < 0 || $splitRate > 1) {
                                            $splitRate = 0.5;
                                        }
                                        $profitToWithdrawable = round($remainingProfit * $splitRate, 2);
                                        $profitToServiceFeeBalance = round($remainingProfit * (1 - $splitRate), 2);
                                        
                                        // 卖家最终提现余额增加 = 本金 + 服务费退还 + 剩余利润的一半
                                        $totalToWithdrawable = $sellerOriginalPrice + $feeRefund + $profitToWithdrawable;
                                        
                                        // 更新卖家余额
                                        $beforeWithdrawable = (float)$seller['withdrawable_money'];
                                        $beforeServiceFee = (float)$seller['service_fee_balance']; // 确权金
                                        
                                        $afterWithdrawable = round($beforeWithdrawable + $totalToWithdrawable, 2);
                                        $afterServiceFee = round($beforeServiceFee + $profitToServiceFeeBalance, 2);
                                        
                                        Db::name('user')->where('id', $sellerId)->update([
                                            'withdrawable_money' => $afterWithdrawable,
                                            'service_fee_balance' => $afterServiceFee,
                                            'update_time' => $now,
                                        ]);
                                        
                                        // 生成流水号和批次号
                                        $flowNo1 = generateSJSFlowNo($sellerId);
                                        $flowNo2 = generateSJSFlowNo($sellerId);
                                        $flowNo3 = generateSJSFlowNo($sellerId);
                                        while ($flowNo2 === $flowNo1) {
                                            $flowNo2 = generateSJSFlowNo($sellerId);
                                        }
                                        while ($flowNo3 === $flowNo1 || $flowNo3 === $flowNo2) {
                                            $flowNo3 = generateSJSFlowNo($sellerId);
                                        }
                                        $batchNo = generateBatchNo('MATCHING_SELLER_INCOME', $consignmentId);
                                        
                                        // 记录卖家收益日志 - 拆分为本金和收益两部分
                                        
                                        // 1. 本金退回日志
                                        $logBefore = $beforeWithdrawable;
                                        $logAfter = round($logBefore + $sellerOriginalPrice, 2);
                                        
                                        Db::name('user_money_log')->insert([
                                            'user_id' => $sellerId,
                                            'flow_no' => $flowNo1,
                                            'batch_no' => $batchNo,
                                            'biz_type' => 'matching_seller_income',
                                            'biz_id' => $consignmentId,
                                            'field_type' => 'withdrawable_money', // 可提现余额变动
                                            'money' => $sellerOriginalPrice,
                                            'before' => $logBefore,
                                            'after' => $logAfter,
                                            'memo' => '交易' . $itemInfo['title'] . '成功',
                                            'create_time' => $now,
                                        ]);
                                        
                                        // 2. 交易收益日志（费返+利润）
                                        $incomePart = round($feeRefund + $profitToWithdrawable, 2);
                                        if ($incomePart > 0) {
                                            $logBefore = $logAfter;
                                            $logAfter = round($logBefore + $incomePart, 2);
                                            
                                            Db::name('user_money_log')->insert([
                                                'user_id' => $sellerId,
                                                'flow_no' => $flowNo2,
                                                'batch_no' => $batchNo,
                                                'biz_type' => 'matching_seller_income',
                                                'biz_id' => $consignmentId,
                                                'field_type' => 'withdrawable_money', // 可提现余额变动
                                                'money' => $incomePart,
                                                'before' => $logBefore,
                                                'after' => $logAfter,
                                                'memo' => '【交易收益】' . $itemInfo['title'],
                                                'create_time' => $now,
                                            ]);
                                        }
                                        
                                        // 如果有确权金收益，也记录日志
                                        if ($profitToServiceFeeBalance > 0) {
                                            Db::name('user_money_log')->insert([
                                                'user_id' => $sellerId,
                                                'flow_no' => $flowNo3,
                                                'batch_no' => $batchNo,
                                                'biz_type' => 'matching_seller_income',
                                                'biz_id' => $consignmentId,
                                                'field_type' => 'service_fee_balance',
                                                'money' => $profitToServiceFeeBalance,
                                                'before' => $beforeServiceFee,
                                                'after' => $afterServiceFee,
                                                'memo' => '【确权收益】' . $itemInfo['title'],
                                                'create_time' => $now,
                                            ]);
                                        }
                                        
                                        // 记录活动日志
                                        Db::name('user_activity_log')->insert([
                                            'user_id' => $sellerId,
                                            'action_type' => 'matching_seller_income',
                                            'change_field' => 'withdrawable_money,service_fee_balance',
                                            'change_value' => json_encode([
                                                'withdrawable_money' => $totalToWithdrawable,
                                                'service_fee_balance' => $profitToServiceFeeBalance,
                                            ], JSON_UNESCAPED_UNICODE),
                                            'before_value' => json_encode([
                                                'withdrawable_money' => $beforeWithdrawable,
                                                'service_fee_balance' => $beforeServiceFee,
                                            ], JSON_UNESCAPED_UNICODE),
                                            'after_value' => json_encode([
                                                'withdrawable_money' => $afterWithdrawable,
                                                'service_fee_balance' => $afterServiceFee,
                                            ], JSON_UNESCAPED_UNICODE),
                                            'remark' => sprintf('卖出:%s. 本金:%.2f. 提现收益:%.2f. 确权收益:%.2f', 
                                                $itemInfo['title'], $sellerOriginalPrice, $incomePart, $profitToServiceFeeBalance),
                                            'extra' => json_encode([
                                                'item_id' => $itemId,
                                                'item_title' => $itemInfo['title'],
                                                'order_id' => $orderId,
                                                'buyer_id' => $userId,
                                                'original_price' => $sellerOriginalPrice,
                                                'sell_price' => $itemPrice,
                                                'fee_refund' => $feeRefund,
                                            ], JSON_UNESCAPED_UNICODE),
                                            'create_time' => $now,
                                        ]);
                                        
                                        $output->writeln("    💰 卖家（用户ID {$sellerId}）：原价 {$sellerOriginalPrice} → 售价 {$itemPrice}，利润 {$profit}（可提现 {$profitToWithdrawable} + 消费金 {$profitToScore}）");
                                        
                                        // ========== 代理商佣金分配 ==========
                                        // 佣金计算基数为卖家的利润
                                        if ($profit > 0) {
                                            $this->distributeAgentCommission($sellerId, $profit, $itemInfo['title'], $consignment['id'] ?? 0, $orderNo, $orderId, $now, $output);
                                        }
                                    }
                                } else {
                                    // 没有寄售记录或配置不允许分配，卖家视为平台（从库存购买）
                                    $platformProfitRate = (float)(get_sys_config('platform_profit_rate') ?? 0.5);
                                    if ($platformProfitRate < 0 || $platformProfitRate > 1) {
                                        $platformProfitRate = 0.5;
                                    }
                                    $profit = $itemPrice * $platformProfitRate;
                                    $output->writeln("    平台收益：本金 {$itemPrice} + 利润 {$profit} = " . ($itemPrice + $profit) . "（平台账户）");
                                }

                                // 更新撮合池记录状态
                                Db::name('collection_matching_pool')
                                    ->where('id', $recordId)
                                    ->update([
                                        'status' => 'matched',
                                        'match_time' => $now,
                                        'match_order_id' => $orderId,
                                        'update_time' => $now,
                                    ]);

                                // 如果是寄售商品，更新寄售记录状态为已售出
                                if ($isConsignmentItem && isset($consignment['id'])) {
                                    Db::name('collection_consignment')
                                        ->where('id', $consignment['id'])
                                        ->update([
                                            'status' => 2, // 已售出
                                            'update_time' => $now
                                        ]);
                                    
                                    // 更新卖家原藏品的寄售状态
                                    if (isset($consignment['user_collection_id'])) {
                                        Db::name('user_collection')
                                            ->where('id', $consignment['user_collection_id'])
                                            ->update([
                                                'consignment_status' => 2, // 已售出
                                                'update_time' => $now
                                            ]);
                                    }
                                }

                                // 检查并升级用户等级，交易用户发放场次+区间绑定寄售券
                                $itemZoneId = (int)($itemInfo['zone_id'] ?? 0);
                                $upgradeResult = UserService::checkAndUpgradeUserAfterPurchase($userId, $sessionId, $itemZoneId);
                                if ($upgradeResult['upgraded']) {
                                    $upgradeMsg = $upgradeResult['new_user_type'] == 2
                                        ? "用户升级为交易用户"
                                        : "用户升级为普通用户";
                                    $output->writeln("    ✓ {$upgradeMsg}");
                                }
                                if ($upgradeResult['coupon_issued']) {
                                    $output->writeln("    ✓ 发放寄售券：场次#{$sessionId}，区间#{$itemZoneId}");
                                }

                                Db::commit();

                                // 🆕 修复：只有在事务成功提交后才执行价格增值，确保撮合失败时不会增值
                                $priceIncrementRate = (float)(get_sys_config('price_increment_rate') ?? 0.05); // 默认5%
                                $newItemPrice = round($itemPrice * (1 + $priceIncrementRate), 2);

                                // 更新藏品的当前价格（collection_item表）
                                Db::name('collection_item')
                                    ->where('id', $itemId)
                                    ->update([
                                        'price' => $newItemPrice,
                                        'price_zone' => $newPriceZone,
                                        'zone_id' => $newZoneId,
                                        'update_time' => $now,
                                    ]);
                                $output->writeln("    📈 交易增值(含税)：{$itemPrice} → {$newItemPrice} (+".round($priceIncrementRate*100)."%, 税".round($serviceFeeRate*100)."%)");

                                $successCount++;
                                $output->writeln("    ✓ 中签成功：用户ID {$userId}, 藏品ID {$itemId}, 订单号 {$orderNo}");
                                
                            } else {
                                // 未中签：退回本金，销毁算力
                                
                                // 退回本金（如果之前有冻结，这里需要退回）
                                // 注意：在 bidBuy 时已经扣除了算力，但没有扣除本金
                                // 所以这里只需要销毁算力，不需要退回本金
                                
                                // 销毁算力（算力已经在 bidBuy 时扣除，这里只是标记）
                                // 实际上算力已经在进入撮合池时扣除了，未中签时算力不退回

                                // 更新撮合池记录状态为已取消
                                Db::name('collection_matching_pool')
                                    ->where('id', $recordId)
                                    ->update([
                                        'status' => 'cancelled',
                                        'update_time' => $now,
                                    ]);

                                // 修复：未中签退款统一退回可用余额（专项金）
                                $reservationToRefund = Db::name('trade_reservations')
                                    ->where('user_id', $userId)
                                    ->where('session_id', $sessionId)
                                    ->where('status', 0)
                                    ->lock(true)
                                    ->find();
                                if ($reservationToRefund) {
                                    $refundAmt = (float)$reservationToRefund['freeze_amount'];
                                    
                                    // 统一退回 balance_available（可用余额/专项金）
                                    $beforeBalance = (float)($user['balance_available'] ?? 0);
                                    $afterBalance = round($beforeBalance + $refundAmt, 2);
                                    
                                    Db::name('user')->where('id', $userId)->update([
                                        'balance_available' => $afterBalance,
                                        'update_time' => $now,
                                    ]);
                                    
                                    // 记录可用余额变动日志
                                    $flowNo = generateSJSFlowNo($userId);
                                    $batchNo = generateBatchNo('MATCHING_REFUND', $reservationToRefund['id']);
                                    Db::name('user_money_log')->insert([
                                        'user_id' => $userId,
                                        'flow_no' => $flowNo,
                                        'batch_no' => $batchNo,
                                        'biz_type' => 'matching_refund',
                                        'biz_id' => $reservationToRefund['id'],
                                        'field_type' => 'balance_available', // 可用余额变动
                                        'money' => $refundAmt,
                                        'before' => $beforeBalance,
                                        'after' => $afterBalance,
                                        'memo' => '撮合未中签，退回可用余额',
                                        'create_time' => $now,
                                    ]);
                                    
                                    // 记录活动日志
                                    Db::name('user_activity_log')->insert([
                                        'user_id' => $userId,
                                        'related_user_id' => 0,
                                        'action_type' => 'refund',
                                        'change_field' => 'balance_available',
                                        'change_value' => (string)$refundAmt,
                                        'before_value' => (string)$beforeBalance,
                                        'after_value' => (string)$afterBalance,
                                        'remark' => '撮合未中签，退回可用余额',
                                        'create_time' => $now,
                                        'update_time' => $now,
                                    ]);
                                    
                                    // 标记预约为已取消
                                    Db::name('trade_reservations')->where('id', $reservationToRefund['id'])->update([
                                        'status' => 2,
                                        'update_time' => $now,
                                    ]);
                                }

                                // 记录活动日志
                                Db::name('user_activity_log')->insert([
                                    'user_id' => $userId,
                                    'action_type' => 'matching_failed',
                                    'change_field' => 'green_power',
                                    'change_value' => '-' . $powerUsed,
                                    'before_value' => json_encode(['green_power' => $user['green_power'] ?? 0], JSON_UNESCAPED_UNICODE),
                                    'after_value' => json_encode(['green_power' => ($user['green_power'] ?? 0) - $powerUsed], JSON_UNESCAPED_UNICODE),
                                    'remark' => sprintf('撮合未中签，算力已销毁：%.2f算力', $powerUsed),
                                    'extra' => json_encode([
                                        'item_id' => $itemId,
                                        'item_title' => $itemInfo['title'],
                                        'power_used' => $powerUsed,
                                        'weight' => $recordInfo['weight'],
                                    ], JSON_UNESCAPED_UNICODE),
                                    'create_time' => $now,
                                ]);

                                Db::commit();
                                $refundCount++;
                                $output->writeln("    ✗ 未中签：用户ID {$userId}, 藏品ID {$itemId}，算力已销毁");
                            }
                            
                        } catch (\Exception $e) {
                            Db::rollback();
                            $errorCount++;
                            $output->writeln("    ✗ 处理失败：用户ID {$userId}, 藏品ID {$itemId}, 错误: " . $e->getMessage());
                            // 额外输出调试信息：文件、行号和堆栈，便于定位字段缺失问题
                            $output->writeln("      异常文件: " . $e->getFile());
                            $output->writeln("      异常行号: " . $e->getLine());
                            $output->writeln("      异常堆栈: " . $e->getTraceAsString());
                        }
                    }

                    // 场次撮合结束后，清退未完成的寄售订单
                    $clearResult = UserService::clearUnsoldConsignments($sessionId);
                    if ($clearResult['success'] && $clearResult['cleared_count'] > 0) {
                        $output->writeln("  场次#{$sessionId} 结束，已清退 {$clearResult['cleared_count']} 个未成交寄售订单");
                    } elseif (!$clearResult['success']) {
                         $output->writeln("  场次#{$sessionId} 清退寄售订单失败: " . $clearResult['error']);
                    }
                }
            }

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            $output->writeln("\n" . str_repeat('-', 80));
            $output->writeln("【撮合池撮合结果】");
            $output->writeln("  处理: {$processCount} | 中签: {$successCount} | 未中签: {$refundCount} | 失败: {$errorCount} | 耗时: {$duration}秒");
            $output->writeln(str_repeat('-', 80));
            
            // ========== 新增：盲盒预约撮合（ba_trade_reservations）==========
            $output->writeln("\n[" . date('Y-m-d H:i:s') . "] 开始处理盲盒预约撮合...");
            $blindBoxTotal = 0; // 总预约数
            $blindBoxProcessed = 0; // 实际处理数（进入撮合流程）
            $blindBoxSuccess = 0; // 中签数
            $blindBoxNotWon = 0; // 未中签（无可匹配商品）
            $blindBoxSkipped = 0; // 跳过数（场次未结束或场次不存在）
            $blindBoxFailed = 0; // 失败数（异常错误）
            
            // 获取所有待处理的盲盒预约
            $pendingReservations = Db::name('trade_reservations')
                ->where('status', 0) // pending
                ->where('zone_id', '>', 0)
                ->where('product_id', 0) // 尚未匹配商品
                ->order('weight desc, create_time asc')
                ->select()
                ->toArray();
            
            $blindBoxTotal = count($pendingReservations);

            // 遍历所有待处理的盲盒预约，按场次分组
            $reservationsBySession = [];
            foreach ($pendingReservations as $res) {
                $reservationsBySession[$res['session_id']][] = $res;
            }

            foreach ($reservationsBySession as $sessionId => $sessionReservations) {
                $session = Db::name('collection_session')
                    ->where('id', $sessionId)
                    ->where('status', '1')
                    ->find();

                if (!$session) {
                    // 标记该场次的所有预约为跳过或失败
                    foreach ($sessionReservations as $res) {
                        Db::name('trade_reservations')->where('id', $res['id'])->update(['status' => 3, 'update_time' => $now]); // 标记为跳过
                        $blindBoxSkipped++;
                        $output->writeln("  ⊗ 用户ID {$res['user_id']} 预约跳过（场次 #{$sessionId} 不存在或已下架）");
                    }
                    continue;
                }

                $startTimeStr = $session['start_time'] ?? '';
                $endTimeStr = $session['end_time'] ?? '';
                $isInTradingTime = $this->isTimeInRange($currentTime, $startTimeStr, $endTimeStr);
                $forceMatching = getenv('FORCE_MATCHING') === '1' || (bool)$input->getOption('force');

                if ($isInTradingTime && !$forceMatching) {
                    // 🔧 修复：场次交易时间未结束，暂时跳过不处理（等下次撮合），不要标记为已取消
                    $output->writeln("  → 场次 #{$sessionId} 「{$session['title']}」交易时间未结束（{$startTimeStr}-{$endTimeStr}），跳过处理，等待下次撮合");
                    continue;
                }

                $output->writeln("  开始处理专场【{$session['title']}】的盲盒预约撮合...");

                // 🆕 统计本场次盲盒预约人数
                $blindBoxParticipantCount = Db::name('trade_reservations')
                    ->where('session_id', $sessionId)
                    ->where('status', 0)
                    ->count('DISTINCT user_id');
                $output->writeln("  👥 盲盒预约人数：{$blindBoxParticipantCount}");

                // 🆕 统计本场次可用库存（按分区）
                $stockStats = Db::name('collection_item')
                    ->where('session_id', $sessionId)
                    ->where('status', 1)
                    ->group('zone_id')
                    ->field('zone_id, count(*) as count, sum(stock) as total_stock, min(price) as min_p, max(price) as max_p')
                    ->select();
                foreach ($stockStats as $stat) {
                    $zName = Db::name('price_zone_config')->where('id', $stat['zone_id'])->value('name') ?: '未知分区';
                    $output->writeln("  📦 分区库存【{$zName}】：商品数 {$stat['count']}，库存 {$stat['total_stock']}，价格范围 {$stat['min_p']}-{$stat['max_p']}");
                }

                // ========== 🆕 自动平衡：确保100%成功率 ==========
                $output->writeln("  🔄 开始自动平衡检查（确保100%成功率）...");
                
                // 按资产包+价格分区统计申购数量
                $applyByPackage = Db::name('trade_reservations')
                    ->where('session_id', $sessionId)
                    ->where('status', 0)
                    ->field('package_id, zone_id, COUNT(*) as apply_count, AVG(freeze_amount) as avg_freeze, MIN(freeze_amount) as min_freeze, MAX(freeze_amount) as max_freeze')
                    ->group('package_id, zone_id')
                    ->select()
                    ->toArray();
                
                // 按资产包+价格分区统计寄售数量
                $consignByPackage = Db::name('collection_consignment')
                    ->alias('c')
                    ->join('collection_item ci', 'c.item_id = ci.id')
                    ->where('c.status', 1)
                    ->where('ci.session_id', $sessionId)
                    ->field('c.package_id, ci.zone_id, COUNT(*) as consign_count')
                    ->group('c.package_id, ci.zone_id')
                    ->select()
                    ->toArray();
                
                // 按资产包+价格分区统计系统库存数量（stock > 0）
                $stockByPackage = Db::name('collection_item')
                    ->where('session_id', $sessionId)
                    ->where('status', 1)
                    ->where('stock', '>', 0)
                    ->field('package_id, zone_id, SUM(stock) as stock_count')
                    ->group('package_id, zone_id')
                    ->select()
                    ->toArray();
                
                // 转换为关联数组
                $consignMap = [];
                foreach ($consignByPackage as $c) {
                    $key = $c['package_id'] . '_' . $c['zone_id'];
                    $consignMap[$key] = (int)$c['consign_count'];
                }
                
                $stockMap = [];
                foreach ($stockByPackage as $s) {
                    $key = $s['package_id'] . '_' . $s['zone_id'];
                    $stockMap[$key] = (int)$s['stock_count'];
                }
                
                $autoSupplyCount = 0;
                $autoBuyCount = 0;
                
                foreach ($applyByPackage as $apply) {
                    $packageId = (int)$apply['package_id'];
                    $zoneId = (int)$apply['zone_id'];
                    $applyCount = (int)$apply['apply_count'];
                    $avgFreeze = (float)$apply['avg_freeze'];
                    $minFreeze = (float)($apply['min_freeze'] ?? $avgFreeze);
                    $maxFreeze = (float)($apply['max_freeze'] ?? $avgFreeze);
                    $key = $packageId . '_' . $zoneId;
                    $consignCount = $consignMap[$key] ?? 0;
                    $stockCount = $stockMap[$key] ?? 0;
                    
                    // 可用总量 = 系统库存 + 寄售数量
                    $totalAvailable = $stockCount + $consignCount;
                    
                    $packageName = Db::name('asset_package')->where('id', $packageId)->value('name') ?: "包#{$packageId}";
                    $zoneName = Db::name('price_zone_config')->where('id', $zoneId)->value('name') ?: "区#{$zoneId}";
                    
                    $output->writeln("    📊 【{$packageName}】【{$zoneName}】申购:{$applyCount} | 系统库存:{$stockCount} | 寄售:{$consignCount} | 可用总量:{$totalAvailable}");
                    
                    // 情况1：申购 > 可用藏品总量 => 需要自动补充藏品
                    if ($applyCount > $totalAvailable) {
                        $needSupply = $applyCount - $totalAvailable;
                        $output->writeln("      📥 申购{$applyCount} > 可用{$totalAvailable}，需补充 {$needSupply} 件藏品");
                        
                        // 🔧 修复：使用最小冻结金额作为价格，确保所有用户都能匹配
                        // 因为撮合逻辑要求 price <= freezeAmount，所以补充的藏品价格必须 <= 所有用户的冻结金额
                        $supplyPrice = $minFreeze;
                        $output->writeln("      💰 补充藏品价格: {$supplyPrice} (最小冻结金额，确保所有用户可匹配)");
                        
                        // 获取参考商品来复制属性（优先找同分区，找不到则找同资产包，再找不到则找同场次）
                        $refItem = Db::name('collection_item')
                            ->where('session_id', $sessionId)
                            ->where('package_id', $packageId)
                            ->where('zone_id', $zoneId)
                            ->order('id desc')
                            ->find();
                        
                        // 如果找不到同分区的，尝试找同资产包的其他分区
                        if (!$refItem) {
                            $refItem = Db::name('collection_item')
                                ->where('session_id', $sessionId)
                                ->where('package_id', $packageId)
                                ->order('id desc')
                                ->find();
                        }
                        
                        // 如果还是找不到，尝试找同场次的其他资产包
                        if (!$refItem) {
                            $refItem = Db::name('collection_item')
                                ->where('session_id', $sessionId)
                                ->order('id desc')
                                ->find();
                        }
                        
                        if ($refItem) {
                            $packageInfo = Db::name('asset_package')->where('id', $packageId)->find();
                            
                            // 获取分区信息（用于设置 price_zone）
                            $zoneInfo = Db::name('price_zone_config')->where('id', $zoneId)->find();
                            $priceZone = $zoneInfo['name'] ?? $refItem['price_zone'] ?? '';
                            
                            for ($i = 0; $i < $needSupply; $i++) {
                                // 生成唯一资产编号
                                $timestamp = (int)(microtime(true) * 1000);
                                $newAssetCode = $packageId . '-SYS-' . str_pad($packageId, 4, '0', STR_PAD_LEFT) . '-' . $timestamp . rand(100, 999);
                                
                                $newItemData = [
                                    'session_id' => $sessionId,
                                    'package_name' => $packageInfo['name'] ?? $refItem['package_name'] ?? "资产包#{$packageId}",
                                    'title' => $packageInfo['name'] ?? $refItem['title'] ?? "资产包#{$packageId}",
                                    'image' => $refItem['image'] ?? '',
                                    'images' => $refItem['images'] ?? '',
                                    'price' => $supplyPrice, // 🔧 修复：使用最小冻结金额，确保所有用户都能匹配
                                    'issue_price' => $supplyPrice,
                                    'price_zone' => $priceZone,
                                    'description' => '系统自动补充库存',
                                    'asset_anchor' => '',
                                    'artist' => '',
                                    'stock' => 1,
                                    'sales' => 0,
                                    'status' => 1,
                                    'is_physical' => 0,
                                    'sort' => 0,
                                    'create_time' => $now,
                                    'update_time' => $now,
                                    'asset_code' => $newAssetCode,
                                    'tx_hash' => '0x' . md5(uniqid() . microtime(true) . $i),
                                    'owner_id' => 0, // 系统所有
                                    'zone_id' => $zoneId,
                                    'package_id' => $packageId,
                                ];
                                
                                $newId = Db::name('collection_item')->insertGetId($newItemData);
                                $output->writeln("        ✨ 创建藏品 ID:{$newId}，价格:{$supplyPrice}，编号:{$newAssetCode}");
                                $autoSupplyCount++;
                                
                                usleep(1000); // 防止时间戳重复
                            }
                        } else {
                            $output->writeln("        ❌ 无法创建藏品：找不到参考商品（场次 #{$sessionId} 下没有任何商品）");
                        }
                    }
                    
                    // 情况2：寄售 > 申购 => 系统模拟申购买入多余的寄售
                    if ($consignCount > $applyCount) {
                        $needBuy = $consignCount - $applyCount;
                        $output->writeln("      🛒 寄售{$consignCount} > 申购{$applyCount}，系统将买入 {$needBuy} 件");
                        
                        // 获取多余的寄售商品（按价格从低到高，优先买便宜的）
                        $excessConsignments = Db::name('collection_consignment')
                            ->alias('c')
                            ->join('collection_item ci', 'c.item_id = ci.id')
                            ->where('c.status', 1)
                            ->where('c.package_id', $packageId)
                            ->where('ci.zone_id', $zoneId)
                            ->where('ci.session_id', $sessionId)
                            ->order('c.price asc, c.create_time asc')
                            ->limit($needBuy)
                            ->field('c.*, ci.title as item_title')
                            ->select()
                            ->toArray();
                        
                        foreach ($excessConsignments as $consignment) {
                            Db::startTrans();
                            try {
                                $consignmentId = (int)$consignment['id'];
                                $sellerId = (int)$consignment['user_id'];
                                $itemId = (int)$consignment['item_id'];
                                $price = (float)$consignment['price'];
                                $userCollectionId = (int)($consignment['user_collection_id'] ?? 0);
                                $serviceFee = (float)($consignment['service_fee'] ?? 0);
                                
                                // 1. 更新寄售状态为已售出
                                Db::name('collection_consignment')
                                    ->where('id', $consignmentId)
                                    ->update([
                                        'status' => 2, // 已售出
                                        'sold_price' => $price,
                                        'sold_time' => $now,
                                        'update_time' => $now,
                                    ]);
                                
                                // 2. 卖家获得收益（扣除手续费）
                                $sellerIncome = $price - $serviceFee;
                                if ($sellerIncome > 0) {
                                    // 50%到可调度收益，50%到消费金
                                    $toDispatchable = round($sellerIncome * 0.5, 2);
                                    $toConsumption = $sellerIncome - $toDispatchable;
                                    
                                    Db::name('user')->where('id', $sellerId)->inc('balance_available', $toDispatchable)->update();
                                    Db::name('user')->where('id', $sellerId)->inc('consumption_money', $toConsumption)->update();
                                    
                                    // 记录卖家收益日志（显示为正常寄售成交）
                                    $itemTitle = $consignment['item_title'] ?? '藏品';
                                    Db::name('user_money_log')->insert([
                                        'user_id' => $sellerId,
                                        'field_type' => 'balance_available',
                                        'money' => $toDispatchable,
                                        'before' => 0,
                                        'after' => 0,
                                        'memo' => "寄售成交收益（可调度）- {$itemTitle}",
                                        'biz_type' => 'consignment_sold',
                                        'biz_id' => $consignmentId,
                                        'create_time' => $now,
                                    ]);
                                    Db::name('user_money_log')->insert([
                                        'user_id' => $sellerId,
                                        'field_type' => 'consumption_money',
                                        'money' => $toConsumption,
                                        'before' => 0,
                                        'after' => 0,
                                        'memo' => "寄售成交收益（消费金）- {$itemTitle}",
                                        'biz_type' => 'consignment_sold',
                                        'biz_id' => $consignmentId,
                                        'create_time' => $now,
                                    ]);
                                }
                                
                                // 3. 更新用户藏品状态为已售出
                                if ($userCollectionId > 0) {
                                    Db::name('user_collection')
                                        ->where('id', $userCollectionId)
                                        ->update([
                                            'consignment_status' => 2, // 已售出
                                            'update_time' => $now,
                                        ]);
                                }
                                
                                // 4. 藏品回归系统库存
                                Db::name('collection_item')
                                    ->where('id', $itemId)
                                    ->update([
                                        'owner_id' => 0,
                                        'stock' => 1,
                                        'update_time' => $now,
                                    ]);
                                
                                // 5. 记录日志（显示为正常寄售成交，用户不可见系统操作）
                                Db::name('user_activity_log')->insert([
                                    'user_id' => $sellerId,
                                    'related_user_id' => 0,
                                    'action_type' => 'consignment_sold',
                                    'change_field' => 'balance_available',
                                    'change_value' => $sellerIncome,
                                    'before_value' => 0,
                                    'after_value' => $sellerIncome,
                                    'remark' => "寄售成交 - {$itemTitle}，成交价:{$price}",
                                    'create_time' => $now,
                                    'update_time' => $now,
                                ]);
                                
                                Db::commit();
                                $autoBuyCount++;
                                $output->writeln("        ✅ 系统买入寄售ID:{$consignmentId}（卖家:{$sellerId}，价格:{$price}，收益:{$sellerIncome}）");
                                
                            } catch (\Throwable $e) {
                                Db::rollback();
                                $output->writeln("        ❌ 买入失败：" . $e->getMessage());
                            }
                        }
                    }
                    
                    // 从map中移除已处理的
                    unset($consignMap[$key]);
                }
                
                // 处理只有寄售没有申购的情况 => 系统全部买入
                foreach ($consignMap as $key => $consignCount) {
                    if ($consignCount > 0) {
                        list($packageId, $zoneId) = explode('_', $key);
                        $packageName = Db::name('asset_package')->where('id', $packageId)->value('name') ?: "包#{$packageId}";
                        $zoneName = Db::name('price_zone_config')->where('id', $zoneId)->value('name') ?: "区#{$zoneId}";
                        $output->writeln("    🛒 【{$packageName}】【{$zoneName}】只有寄售{$consignCount}无申购，系统全部买入");
                        
                        // 获取所有寄售
                        $allConsignments = Db::name('collection_consignment')
                            ->alias('c')
                            ->join('collection_item ci', 'c.item_id = ci.id')
                            ->where('c.status', 1)
                            ->where('c.package_id', $packageId)
                            ->where('ci.zone_id', $zoneId)
                            ->where('ci.session_id', $sessionId)
                            ->field('c.*, ci.title as item_title')
                            ->select()
                            ->toArray();
                        
                        foreach ($allConsignments as $consignment) {
                            Db::startTrans();
                            try {
                                $consignmentId = (int)$consignment['id'];
                                $sellerId = (int)$consignment['user_id'];
                                $itemId = (int)$consignment['item_id'];
                                $price = (float)$consignment['price'];
                                $userCollectionId = (int)($consignment['user_collection_id'] ?? 0);
                                $serviceFee = (float)($consignment['service_fee'] ?? 0);
                                
                                // 1. 更新寄售状态为已售出
                                Db::name('collection_consignment')
                                    ->where('id', $consignmentId)
                                    ->update([
                                        'status' => 2,
                                        'sold_price' => $price,
                                        'sold_time' => $now,
                                        'update_time' => $now,
                                    ]);
                                
                                // 2. 卖家获得收益
                                $sellerIncome = $price - $serviceFee;
                                $itemTitle = $consignment['item_title'] ?? '藏品';
                                if ($sellerIncome > 0) {
                                    $toDispatchable = round($sellerIncome * 0.5, 2);
                                    $toConsumption = $sellerIncome - $toDispatchable;
                                    
                                    Db::name('user')->where('id', $sellerId)->inc('balance_available', $toDispatchable)->update();
                                    Db::name('user')->where('id', $sellerId)->inc('consumption_money', $toConsumption)->update();
                                    
                                    // 记录日志（显示为正常寄售成交）
                                    Db::name('user_money_log')->insert([
                                        'user_id' => $sellerId,
                                        'field_type' => 'balance_available',
                                        'money' => $toDispatchable,
                                        'before' => 0,
                                        'after' => 0,
                                        'memo' => "寄售成交收益（可调度）- {$itemTitle}",
                                        'biz_type' => 'consignment_sold',
                                        'biz_id' => $consignmentId,
                                        'create_time' => $now,
                                    ]);
                                    Db::name('user_money_log')->insert([
                                        'user_id' => $sellerId,
                                        'field_type' => 'consumption_money',
                                        'money' => $toConsumption,
                                        'before' => 0,
                                        'after' => 0,
                                        'memo' => "寄售成交收益（消费金）- {$itemTitle}",
                                        'biz_type' => 'consignment_sold',
                                        'biz_id' => $consignmentId,
                                        'create_time' => $now,
                                    ]);
                                }
                                
                                // 3. 更新用户藏品状态
                                if ($userCollectionId > 0) {
                                    Db::name('user_collection')
                                        ->where('id', $userCollectionId)
                                        ->update([
                                            'consignment_status' => 2,
                                            'update_time' => $now,
                                        ]);
                                }
                                
                                // 4. 藏品回归系统库存
                                Db::name('collection_item')
                                    ->where('id', $itemId)
                                    ->update([
                                        'owner_id' => 0,
                                        'stock' => 1,
                                        'update_time' => $now,
                                    ]);
                                
                                // 5. 记录日志（显示为正常寄售成交）
                                Db::name('user_activity_log')->insert([
                                    'user_id' => $sellerId,
                                    'related_user_id' => 0,
                                    'action_type' => 'consignment_sold',
                                    'change_field' => 'balance_available',
                                    'change_value' => $sellerIncome,
                                    'before_value' => 0,
                                    'after_value' => $sellerIncome,
                                    'remark' => "寄售成交 - {$itemTitle}，成交价:{$price}",
                                    'create_time' => $now,
                                    'update_time' => $now,
                                ]);
                                
                                Db::commit();
                                $autoBuyCount++;
                                $output->writeln("        ✅ 系统买入寄售ID:{$consignmentId}（卖家:{$sellerId}，价格:{$price}）");
                                
                            } catch (\Throwable $e) {
                                Db::rollback();
                                $output->writeln("        ❌ 买入失败：" . $e->getMessage());
                            }
                        }
                    }
                }
                
                $output->writeln("  🔄 自动平衡完成：补充藏品 {$autoSupplyCount} 件，系统买入 {$autoBuyCount} 件");
                // ========== 自动平衡结束 ==========

                // 2. 遍历该场次的所有有效预约（status=0:待撮合）
                // 🆕 统计该场次中每个用户的申购次数（多次申购优先）
                $userBlindBoxCounts = Db::name('trade_reservations')
                    ->where('session_id', $sessionId)
                    ->where('status', 0)
                    ->field('user_id, COUNT(*) as apply_count')
                    ->group('user_id')
                    ->select()
                    ->column('apply_count', 'user_id');
                
                // 🆕 输出多次申购用户统计
                $multiApplyBlindBoxUsers = array_filter($userBlindBoxCounts, function($count) { return $count > 1; });
                if (!empty($multiApplyBlindBoxUsers)) {
                    $output->writeln("  📊 多次申购用户：" . count($multiApplyBlindBoxUsers) . " 人（优先撮合）");
                }
                
                $reservations = Db::name('trade_reservations')
                    ->where('session_id', $sessionId)
                    ->where('status', 0)
                    ->select()
                    ->toArray();
                
                // 🆕 为每条预约添加用户申购次数，用于排序
                foreach ($reservations as &$res) {
                    $res['user_apply_count'] = $userBlindBoxCounts[$res['user_id']] ?? 1;
                }
                unset($res);
                
                // 🆕 新排序规则：申购次数降序 > 权重降序 > 时间升序
                usort($reservations, function($a, $b) {
                    // 1. 先按用户申购次数降序（多次申购优先）
                    if ($a['user_apply_count'] != $b['user_apply_count']) {
                        return $b['user_apply_count'] - $a['user_apply_count'];
                    }
                    // 2. 再按权重降序
                    if ($a['weight'] != $b['weight']) {
                        return $b['weight'] - $a['weight'];
                    }
                    // 3. 最后按时间升序（早申购优先）
                    return $a['create_time'] - $b['create_time'];
                });

                foreach ($reservations as $reservation) {
                    $reservationId = (int)$reservation['id']; // 预约记录ID
                    $userId = (int)$reservation['user_id'];
                    $sessionId = (int)$reservation['session_id'];
                    $zoneId = (int)$reservation['zone_id'];
                    $packageId = (int)$reservation['package_id']; // 获取用户申请的资产包ID
                    $freezeAmount = (float)$reservation['freeze_amount'];
                    
                    // 如果预约记录没有指定资产包，跳过（新预约必须指定资产包）
                    if ($packageId <= 0) {
                        $blindBoxSkipped++;
                        $output->writeln("  ⊗ 用户ID {$userId} 预约跳过（预约记录未指定资产包，package_id = 0）");
                        continue;
                    }
                    
                    // 检查场次时间是否已结束
                    $session = Db::name('collection_session')
                        ->where('id', $sessionId)
                        ->where('status', '1')
                        ->find();
                    
                    if (!$session) {
                        $blindBoxSkipped++;
                        $output->writeln("  ⊗ 用户ID {$userId} 预约跳过（场次 #{$sessionId} 不存在或已下架）");
                        continue;
                    }
                    
                    $startTimeStr = $session['start_time'] ?? '';
                    $endTimeStr = $session['end_time'] ?? '';
                    $isInTradingTime = $this->isTimeInRange($currentTime, $startTimeStr, $endTimeStr);
                    
                    // 只在场次结束后撮合（或强制撮合模式）
                    $forceMatching = getenv('FORCE_MATCHING') === '1' || (bool)$input->getOption('force');
                    if ($isInTradingTime && !$forceMatching) {
                        $blindBoxSkipped++;
                        $output->writeln("  ⊗ 用户ID {$userId} 预约跳过（场次 #{$sessionId} 「{$session['title']}」交易时间未结束）");
                        continue;
                    }
                    
                    // 进入实际撮合流程
                    $blindBoxProcessed++;
                    
                    try {
                        Db::startTrans();
                        
                        // 按优先级从资产包中匹配商品：
                        // 1. 旧资产包优先（is_old_asset_package=1 最优先，其次 ap.id 小的优先）
                        // 2. 老用户优先（用户注册时间早的优先）
                        // 3. 系统单优先（user_id=0 的优先）
                        // 4. 早寄售的优先（create_time 早的优先）
                        // 修改：关联 collection_item 使用其动态 zone_id 进行判定，而非 asset_package 的静态 zone_id
                        // 🔧 修复：添加 package_id 限制，只匹配用户申请的资产包
                        $availableConsignment = Db::name('collection_consignment')
                            ->alias('c')
                            ->join('collection_item ci', 'c.item_id = ci.id') // 关联商品表获取最新分区信息
                            ->leftJoin('asset_package ap', 'c.package_id = ap.id')
                            ->leftJoin('user u', 'c.user_id = u.id')
                            ->leftJoin('user_collection uc', 'c.user_collection_id = uc.id')
                            ->leftJoin('price_zone_config pz', 'ci.zone_id = pz.id') // 使用商品的 zone_id
                            ->where('c.status', 1) // 寄售中
                            ->where(function($query) use ($zoneId, $sessionId, $packageId) {
                                // 严格匹配指定资产包的寄售商品
                                $query->where('c.package_id', $packageId);
                                // 匹配指定分区 (使用商品的 dynamic zone_id)
                                $query->where(function($q) use ($zoneId) {
                                    $q->where('ci.zone_id', $zoneId)
                                      ->whereOr('ci.zone_id', 0);
                                })->where('ci.session_id', $sessionId); // 确保商品属于该场次
                            })
                            ->where('c.price', '<=', $freezeAmount) // 价格不超过冻结金额
                            ->field('c.*, ap.id as package_id, ap.name as package_name, u.create_time as user_reg_time, uc.is_old_asset_package')
                            ->order([
                                'uc.is_old_asset_package' => 'desc',  // 1. 旧资产解锁包最优先
                                'ap.id' => 'asc',                     // 2. 旧资产包优先（历史遗留）
                                'u.create_time' => 'asc',             // 3. 老用户优先
                                // 修改为用户寄售优先：
                                'c.user_id' => 'desc', // user_id大的（真实用户）优先
                                'c.create_time' => 'asc',             // 5. 早寄售的优先
                            ])
                            ->lock(true)
                            ->find();
                        
                        // 若无寄售，则尝试官方上架库存（与普通包一致，user_id=0）
                        $isOfficial = false;
                        if (!$availableConsignment) {
                            // 只匹配指定资产包的官方库存
                            $officialItem = Db::name('collection_item')
                                ->where('status', 1)
                                ->where('stock', '>', 0)
                                ->where('session_id', $sessionId)
                                ->where('package_id', $packageId)  // 严格匹配指定的资产包
                                ->where(function($q) use ($zoneId) {
                                    $q->where('zone_id', $zoneId)->whereOr('zone_id', 0);
                                })
                                ->where('price', '<=', $freezeAmount)
                                ->order('id asc')
                                ->lock(true)
                                ->find();

                            if ($officialItem) {
                                $isOfficial = true;
                                // 使用预约记录的 package_id，确保撮合结果与预约一致
                                $matchedPackageId = $packageId;
                                
                                $availableConsignment = [
                                    'id' => 0,
                                    'item_id' => $officialItem['id'],
                                    'price' => $officialItem['price'],
                                    'user_id' => 0,
                                    'package_id' => $matchedPackageId,
                                ];
                            }
                            // 如果没有找到官方库存，availableConsignment 保持为 null，后续会标记为未中签并退回冻结金额
                        }

                    if (!$availableConsignment) {
                        // 没有可匹配的商品，标记为未中签
                        Db::name('trade_reservations')
                            ->where('id', $reservation['id'])  // 🔧 修复：添加缺失的WHERE条件
                            ->update([
                                'status' => 2, // 未中签
                                'update_time' => $now,
                            ]);
                        
                        // 退还冻结金额（统一退回可用余额）
                        $userForBlindBoxRefund = Db::name('user')->where('id', $userId)->find();
                        $beforeBalanceBlindBox = (float)($userForBlindBoxRefund['balance_available'] ?? 0);
                        $afterBalanceBlindBox = round($beforeBalanceBlindBox + $freezeAmount, 2);
                        
                        Db::name('user')
                            ->where('id', $userId)
                            ->update([
                                'balance_available' => $afterBalanceBlindBox,
                                'update_time' => $now,
                            ]);
                        
                        // 记录可用余额变动日志
                        $flowNo = generateSJSFlowNo($userId);
                        $batchNo = generateBatchNo('BLIND_BOX_REFUND', $reservationId);
                        Db::name('user_money_log')->insert([
                            'user_id' => $userId,
                            'flow_no' => $flowNo,
                            'batch_no' => $batchNo,
                            'biz_type' => 'blind_box_refund',
                            'biz_id' => $reservationId,
                            'field_type' => 'balance_available', // 可用余额变动
                            'money' => $freezeAmount,
                            'before' => $beforeBalanceBlindBox,
                            'after' => $afterBalanceBlindBox,
                            'memo' => '盲盒预约未中签，退还冻结金额（退回可用余额）',
                            'create_time' => $now,
                        ]);
                        
                        // 记录活动日志
                        Db::name('user_activity_log')->insert([
                            'user_id' => $userId,
                            'related_user_id' => 0,
                            'action_type' => 'refund',
                            'change_field' => 'balance_available',
                            'change_value' => (string)$freezeAmount,
                            'before_value' => (string)$beforeBalanceBlindBox,
                            'after_value' => (string)$afterBalanceBlindBox,
                            'remark' => '盲盒预约未中签，退还冻结金额（退回可用余额）',
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);
                        
                        Db::commit();
                        $blindBoxNotWon++;
                        $output->writeln("  ✗ 用户ID {$userId} 盲盒预约未中签（无可匹配商品），已退还冻结金额 {$freezeAmount}");
                        continue;
                    }
                    
                    // 找到可匹配商品，执行撮合
                    $itemId = (int)$availableConsignment['item_id'];
                    $itemPrice = (float)$availableConsignment['price'];
                    $sellerId = (int)$availableConsignment['user_id'];
                    
                    // 获取商品信息
                    $itemInfo = Db::name('collection_item')
                        ->where('id', $itemId)
                        ->find();
                    
                    if (!$itemInfo) {
                        Db::rollback();
                        continue;
                    }
                    
                    // 计算差价退还
                    $refundDiff = $freezeAmount - $itemPrice;
                    
                    // 创建订单
                    $orderNo = 'BB' . date('YmdHis') . str_pad($userId, 6, '0', STR_PAD_LEFT) . mt_rand(1000, 9999);
                    $orderId = Db::name('collection_order')->insertGetId([
                        'order_no' => $orderNo,
                        'user_id' => $userId,
                        'total_amount' => $itemPrice,
                        'pay_type' => 'money',
                        'status' => 'paid',
                        'pay_time' => $now,
                        'complete_time' => $now,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    
                    // 创建订单明细
                    Db::name('collection_order_item')->insert([
                        'order_id' => $orderId,
                        'item_id' => $itemId,
                        'item_title' => $itemInfo['title'],
                        'item_image' => $itemInfo['image'],
                        'price' => $itemPrice,
                        'quantity' => 1,
                        'subtotal' => $itemPrice,
                        'create_time' => $now,
                    ]);
                    
                    // 更新预约记录
                    Db::name('trade_reservations')
                        ->where('id', $reservation['id'])
                        ->update([
                            'product_id' => $itemId,
                            'package_id' => (int)$availableConsignment['package_id'],
                            'match_order_id' => $orderId,
                            'match_time' => $now,
                            'status' => 1, // 已中签
                            'update_time' => $now,
                        ]);
                    
                    // 更新寄售记录状态
                    if ($isOfficial) {
                        // 官方库存扣减，售罄则下架
                        $stock = (int)($itemInfo['stock'] ?? 0);
                        Db::name('collection_item')->where('id', $itemId)->dec('stock', 1)->update(['update_time' => $now]);
                        if ($stock <= 1) {
                            Db::name('collection_item')->where('id', $itemId)->update(['status' => 0, 'update_time' => $now]);
                        }
                    } else {
                        // 🔧 修复：只有寄售商品才调用寄售状态更新服务
                        // 官方商品的 availableConsignment['id'] = 0，会导致 "miss update condition" 错误
                        if (!empty($availableConsignment['id'])) {
                            ConsignmentService::updateStatusDirect(
                                (int)$availableConsignment['id'],
                                ConsignmentService::STATUS_SOLD,
                                isset($availableConsignment['user_collection_id']) ? (int)$availableConsignment['user_collection_id'] : null
                            );
                        }
                    }
                    
                    // 更新资产包统计
                    if ((int)$availableConsignment['package_id'] > 0) {
                        Db::name('asset_package')
                            ->where('id', (int)$availableConsignment['package_id'])
                            ->inc('sold_count', 1)
                            ->update(['update_time' => $now]);
                    }

                    // 如果有差价，退还给买家（退回可用余额）
                    if ($refundDiff > 0) {
                        // 获取退款前的可用余额
                        $userForRefund = Db::name('user')->where('id', $userId)->find();
                        $beforeBalanceForRefund = (float)($userForRefund['balance_available'] ?? 0);
                        $afterBalanceForRefund = round($beforeBalanceForRefund + $refundDiff, 2);
                        
                        // 只更新可用余额，不更新 money（money 是派生值）
                        Db::name('user')
                            ->where('id', $userId)
                            ->update([
                                'balance_available' => $afterBalanceForRefund,
                                'update_time' => $now,
                            ]);
                        
                        // 记录可用余额变动日志（包含商品ID关联）
                        $flowNo = generateSJSFlowNo($userId);
                        $batchNo = generateBatchNo('BLIND_BOX_DIFF_REFUND', $reservationId);
                        Db::name('user_money_log')->insert([
                            'user_id' => $userId,
                            'flow_no' => $flowNo,
                            'batch_no' => $batchNo,
                            'biz_type' => 'blind_box_diff_refund',
                            'biz_id' => $reservationId,
                            'field_type' => 'balance_available', // 可用余额变动
                            'money' => $refundDiff,
                            'before' => $beforeBalanceForRefund,
                            'after' => $afterBalanceForRefund,
                            'memo' => '盲盒中签退还差价（退回可用余额）：' . $itemInfo['title'] . '（商品ID：' . $itemId . '）',
                            'create_time' => $now,
                        ]);
                        
                        // 记录活动日志（包含商品ID关联）
                        Db::name('user_activity_log')->insert([
                            'user_id' => $userId,
                            'related_user_id' => 0,
                            'action_type' => 'refund',
                            'change_field' => 'balance_available',
                            'change_value' => (string)$refundDiff,
                            'before_value' => (string)$beforeBalanceForRefund,
                            'after_value' => (string)$afterBalanceForRefund,
                            'remark' => '盲盒中签退还差价（退回可用余额）',
                            'extra' => json_encode([
                                'item_id' => $itemId,
                                'item_title' => $itemInfo['title'],
                                'item_price' => $itemPrice,
                                'freeze_amount' => $freezeAmount,
                                'refund_amount' => $refundDiff,
                                'order_id' => $orderId,
                                'order_no' => $orderNo,
                            ], JSON_UNESCAPED_UNICODE),
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);
                    }
                    
                    // 给卖家发放收益（按本金+利润分配规则）
                    if ($sellerId > 0) {
                        // 获取卖家信息和买入价
                        $seller = Db::name('user')->where('id', $sellerId)->lock(true)->find();
                        if ($seller) {
                            // 查找卖家的买入价（本金）
                            $ucId = isset($availableConsignment['user_collection_id']) ? (int)$availableConsignment['user_collection_id'] : 0;
                            if ($ucId > 0) {
                                $sellerCollection = Db::name('user_collection')->where('id', $ucId)->find();
                            } else {
                                $sellerCollection = Db::name('user_collection')
                                    ->where('user_id', $sellerId)
                                    ->where('item_id', $itemId)
                                    ->order('id asc')
                                    ->find();
                            }
                            
                            $buyPrice = $sellerCollection ? (float)$sellerCollection['price'] : 0;
                            if ($buyPrice <= 0) {
                                $buyPrice = $itemPrice; // 兼容处理：如果找不到买入价，使用寄售价作为本金
                            }
                            
                            // 🆕 判断是否是旧资产包（旧资产包不返还手续费）
                            $isOldAssetPackage = $sellerCollection && (int)($sellerCollection['is_old_asset_package'] ?? 0) === 1;
                            
                            // 计算利润
                            $profit = $itemPrice - $buyPrice;
                            if ($profit < 0) {
                                $profit = 0; // 亏损情况：利润为0
                            }
                            
                            // 🆕 新收益分配规则：
                            // 1. 本金*3%的服务费金额直接到账提现余额（旧资产包不返还）
                            // 2. 剩余利润（约2%）对半到账提现余额和确权金（service_fee_balance）
                            
                            $serviceFeeRate = (float)(get_sys_config('consignment_service_fee_rate') ?? 0.03);
                            // 旧资产包不返还手续费
                            $feeRefund = $isOldAssetPackage ? 0 : round($buyPrice * $serviceFeeRate, 2);
                            
                            $remainingProfit = max(0, $profit - $feeRefund);
                            
                            // 剩余利润拆分（从配置读取）
                            $splitRate = (float)(get_sys_config('seller_profit_split_rate') ?? 0.5);
                            if ($splitRate < 0 || $splitRate > 1) {
                                $splitRate = 0.5;
                            }
                            $profitToWithdrawable = round($remainingProfit * $splitRate, 2);
                            $profitToScore = round($remainingProfit * (1 - $splitRate), 2);
                            
                            // 卖家最终提现余额增加 = 本金 + 服务费退还 + 剩余利润的一半
                            $sellerTotalWithdrawable = $buyPrice + $feeRefund + $profitToWithdrawable;
                            
                            // 更新卖家余额
                            $beforeWithdrawable = (float)$seller['withdrawable_money'];
                            $beforeScore = (float)$seller['score'];
                            
                            $afterWithdrawable = round($beforeWithdrawable + $sellerTotalWithdrawable, 2);
                            $afterScore = round($beforeScore + $profitToScore, 2);
                            
                            Db::name('user')->where('id', $sellerId)->update([
                                'withdrawable_money' => $afterWithdrawable,
                                'score' => $afterScore,
                                'update_time' => $now,
                            ]);
                            
                            // 生成流水号和批次号
                            $flowNo1 = generateSJSFlowNo($sellerId);
                            $flowNo2 = generateSJSFlowNo($sellerId);
                            $flowNo3 = generateSJSFlowNo($sellerId);
                            while ($flowNo2 === $flowNo1) {
                                $flowNo2 = generateSJSFlowNo($sellerId);
                            }
                            while ($flowNo3 === $flowNo1 || $flowNo3 === $flowNo2) {
                                $flowNo3 = generateSJSFlowNo($sellerId);
                            }
                            $batchNo = generateBatchNo('MATCHING_OFFICIAL_SELLER', $orderId);
                            
                            // 记录可提现余额变动日志 - 拆分本金和收益
                                        
                            // 1. 本金退回
                            $logBefore = $beforeWithdrawable;
                            $logAfter = round($logBefore + $buyPrice, 2);
                            
                            Db::name('user_money_log')->insert([
                                'user_id' => $sellerId,
                                'flow_no' => $flowNo1,
                                'batch_no' => $batchNo,
                                'biz_type' => 'matching_official_seller',
                                'biz_id' => $orderId,
                                'field_type' => 'withdrawable_money',
                                'money' => $buyPrice,
                                'before' => $logBefore,
                                'after' => $logAfter,
                                'memo' => '交易' . $itemInfo['title'] . '成功',
                                'create_time' => $now,
                            ]);
                            
                            // 2. 收益（费返+提现利润）
                            $incomePart = round($feeRefund + $profitToWithdrawable, 2);
                            if ($incomePart > 0) {
                                $logBefore = $logAfter;
                                $logAfter = round($logBefore + $incomePart, 2);
                                
                                Db::name('user_money_log')->insert([
                                    'user_id' => $sellerId,
                                    'flow_no' => $flowNo2,
                                    'batch_no' => $batchNo,
                                    'biz_type' => 'matching_official_seller',
                                    'biz_id' => $orderId,
                                    'field_type' => 'withdrawable_money',
                                    'money' => $incomePart,
                                    'before' => $logBefore,
                                    'after' => $logAfter,
                                    'memo' => '【交易收益】' . $itemInfo['title'],
                                    'create_time' => $now,
                                ]);
                            }
                            
                            // 如果有确权金收益（消费金），记录到user_score_log表
                            if ($profitToScore > 0) {
                                Db::name('user_score_log')->insert([
                                    'user_id' => $sellerId,
                                    'flow_no' => $flowNo3,
                                    'batch_no' => $batchNo,
                                    'biz_type' => 'matching_official_seller',
                                    'biz_id' => $orderId,
                                    'user_collection_id' => $ucId,
                                    'item_id' => $itemId,
                                    'title_snapshot' => $itemInfo['title'],
                                    'image_snapshot' => $itemInfo['image'] ?? '',
                                    'score' => $profitToScore,
                                    'before' => $beforeScore,
                                    'after' => $afterScore,
                                    'memo' => '【确权收益】' . $itemInfo['title'],
                                    'create_time' => $now,
                                ]);
                            }
                            
                            // 记录活动日志
                            Db::name('user_activity_log')->insert([
                                'user_id' => $sellerId,
                                'related_user_id' => $userId,
                                'action_type' => 'consignment_income',
                                'change_field' => 'withdrawable_money,score',
                                'change_value' => json_encode([
                                    'withdrawable_money' => $sellerTotalWithdrawable,
                                    'score' => $profitToScore,
                                ], JSON_UNESCAPED_UNICODE),
                                'before_value' => json_encode([
                                    'withdrawable_money' => $beforeWithdrawable,
                                    'score' => $beforeScore,
                                ], JSON_UNESCAPED_UNICODE),
                                'after_value' => json_encode([
                                    'withdrawable_money' => $afterWithdrawable,
                                    'score' => $afterScore,
                                ], JSON_UNESCAPED_UNICODE),
                                'remark' => sprintf('卖出:%s. 本金:%.2f. 提现收益:%.2f. 确权收益:%.2f', 
                                    $itemInfo['title'], $buyPrice, $incomePart, $profitToScore),
                                'create_time' => $now,
                                'update_time' => $now,
                            ]);
                            
                            // 代理佣金分配（如果有利润）
                            if ($profit > 0) {
                                $this->distributeAgentCommission($sellerId, $profit, $itemInfo['title'], 0, $orderNo, $orderId, $now, $output);
                            }
                        }
                    }
                    
                    // 创建买家藏品记录
                    Db::name('user_collection')->insert([
                        'user_id' => $userId,
                        'order_id' => $orderId,
                        'order_item_id' => 0,
                        'item_id' => $itemId,
                        'title' => $itemInfo['title'] ?? '',
                        'image' => $itemInfo['image'] ?? '',
                        'price' => $itemPrice,
                        'buy_time' => $now,
                        'delivery_status' => 0,
                        'consignment_status' => 0,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    
                    // 检查并升级用户等级，交易用户发放场次+区间绑定寄售券
                    $upgradeResult = UserService::checkAndUpgradeUserAfterPurchase($userId, $sessionId, $zoneId);
                    if ($upgradeResult['upgraded']) {
                        $upgradeMsg = $upgradeResult['new_user_type'] == 2 
                            ? "用户升级为交易用户" 
                            : "用户升级为普通用户";
                        $output->writeln("  ✓ {$upgradeMsg}");
                    }
                    if ($upgradeResult['coupon_issued']) {
                        $output->writeln("  ✓ 发放寄售券：场次#{$sessionId}，区间#{$zoneId}");
                    }

                    Db::commit();

                    // 🆕 修复：只有在事务成功提交后才执行价格增值，确保盲盒撮合失败时不会增值
                    $priceIncrementRate = (float)(get_sys_config('price_increment_rate') ?? 0.05); // 默认5%
                    $newItemPrice = round($itemPrice * (1 + $priceIncrementRate), 2);

                    // 查找新价格对应的分区
                    $zone = Db::name('price_zone_config')
                        ->where('status', '1')
                        ->where('min_price', '<=', $newItemPrice)
                        ->where('max_price', '>=', $newItemPrice)
                        ->find();
                    // 截断分区名称，确保不超过10个字符且不截断中文字符
                    $zoneName = $zone ? $zone['name'] : '';
                    if (mb_strlen($zoneName, 'UTF-8') > 10) {
                        $newPriceZone = mb_substr($zoneName, 0, 10, 'UTF-8');
                    } else {
                        $newPriceZone = $zoneName;
                    }
                    $newZoneId = $zone ? (int)$zone['id'] : 0;

                    // 更新藏品的当前价格（collection_item表）
                    Db::name('collection_item')
                        ->where('id', $itemId)
                        ->update([
                            'price' => $newItemPrice,
                            'price_zone' => $newPriceZone,
                            'zone_id' => $newZoneId,
                            'update_time' => $now,
                        ]);
                    $output->writeln("    📈 盲盒交易增值：{$itemPrice} → {$newItemPrice} (+" . round($priceIncrementRate * 100) . "%)");

                    $blindBoxSuccess++;
                    $output->writeln("  ✓ 用户ID {$userId} 盲盒中签，商品ID {$itemId}，价格 {$itemPrice}，退差 {$refundDiff}");
                    
                } catch (\Exception $e) {
                    Db::rollback();
                    $blindBoxFailed++;
                    $output->writeln("  ✗ 用户ID {$userId} 盲盒撮合失败: " . $e->getMessage());
                }
            }
            } // End of foreach ($reservationsBySession)
            
            $output->writeln("\n" . str_repeat('-', 80));
            $output->writeln("【盲盒预约撮合结果】");
            $output->writeln("  总预约: {$blindBoxTotal} | 处理: {$blindBoxProcessed} | 中签: {$blindBoxSuccess} | 未中签: {$blindBoxNotWon} | 跳过: {$blindBoxSkipped} | 失败: {$blindBoxFailed}");
            $output->writeln(str_repeat('-', 80));
            
            // 场次结束自动下架寄售订单（最后执行，确保撮合时有商品可用）
            $this->autoOffShelfConsignments($output, $now, $currentTime);

            // 总结输出
            $output->writeln("\n" . str_repeat('=', 80));
            $output->writeln("[" . date('Y-m-d H:i:s') . "] {$runModeSymbol} 撮合任务完成 - {$runMode}");
            $output->writeln(str_repeat('=', 80) . "\n");
            
        } catch (\Exception $e) {
            $output->writeln("\n" . str_repeat('!', 80));
            $output->writeln("撮合处理异常: " . $e->getMessage());
            $output->writeln("错误文件: " . $e->getFile());
            $output->writeln("错误行号: " . $e->getLine());
            $output->writeln(str_repeat('!', 80));
        } finally {
            // ========== 释放进程锁 ==========
            if ($this->lockFileHandle) {
                flock($this->lockFileHandle, LOCK_UN);
                fclose($this->lockFileHandle);
                $this->lockFileHandle = null;
            }
        }
        
        return 0;
    }

    /**
     * 分配代理佣金
     * @param int $sellerId 卖家ID
     * @param float $profit 利润（佣金计算基数）
     * @param string $itemTitle 商品标题
     * @param int $consignmentId 寄售记录ID
     * @param string $orderNo 订单号
     * @param int $orderId 订单ID
     * @param int $now 当前时间戳
     * @param Output $output 输出对象
     * @return void
     */
    private function distributeAgentCommission(int $sellerId, float $profit, string $itemTitle, int $consignmentId, string $orderNo, int $orderId, int $now, Output $output): void
    {
        // 从配置读取佣金比例
        $directRate = (float)(get_sys_config('agent_direct_rate') ?? 0.10);
        $indirectRate = (float)(get_sys_config('agent_indirect_rate') ?? 0.05);
        $teamRates = [
            1 => (float)(get_sys_config('agent_team_level1') ?? 0.09),
            2 => (float)(get_sys_config('agent_team_level2') ?? 0.12),
            3 => (float)(get_sys_config('agent_team_level3') ?? 0.15),
            4 => (float)(get_sys_config('agent_team_level4') ?? 0.18),
            5 => (float)(get_sys_config('agent_team_level5') ?? 0.21),
        ];
        $sameLevelRate = (float)(get_sys_config('agent_same_level_rate') ?? 0.10); // 同级奖比例

        // 确保比例在有效范围内
        if ($directRate < 0 || $directRate > 1) {
            $directRate = 0.10;
        }
        if ($indirectRate < 0 || $indirectRate > 1) {
            $indirectRate = 0.05;
        }
        foreach ($teamRates as $level => &$rate) {
            if ($rate < 0 || $rate > 1) {
                $rate = 0.09 + ($level - 1) * 0.03; // 默认值
            }
        }
        unset($rate);
        if ($sameLevelRate < 0 || $sameLevelRate > 1) {
            $sameLevelRate = 0.10;
        }

        // 获取卖家信息
        $seller = Db::name('user')->where('id', $sellerId)->find();
        if (!$seller) {
            return;
        }

        // 1. 直推佣金：获取卖家的邀请人（直推）
        $directInviterId = (int)$seller['inviter_id'];
        $directInviter = null;
        if ($directInviterId > 0) {
            $directInviter = Db::name('user')
                ->where('id', $directInviterId)
                ->lock(true)
                ->find();
            
            if ($directInviter) {
                $directCommission = round($profit * $directRate, 2);
                if ($directCommission > 0) {
                    // 修复：直推佣金发放到可提现余额
                    $directBeforeWithdrawable = (float)$directInviter['withdrawable_money'];
                    $directAfterWithdrawable = round($directBeforeWithdrawable + $directCommission, 2);
                    
                    Db::name('user')
                        ->where('id', $directInviterId)
                        ->update([
                            'withdrawable_money' => $directAfterWithdrawable,
                            'update_time' => $now,
                        ]);

                    // 记录可提现余额变动日志
                    $flowNo = generateSJSFlowNo($directInviterId);
                    $batchNo = generateBatchNo('MATCHING_COMMISSION', $orderId);
                    Db::name('user_money_log')->insert([
                        'user_id' => $directInviterId,
                        'flow_no' => $flowNo,
                        'batch_no' => $batchNo,
                        'biz_type' => 'matching_commission',
                        'biz_id' => $orderId,
                        'field_type' => 'withdrawable_money', // 可提现余额变动
                        'money' => $directCommission,
                        'before' => $directBeforeWithdrawable,
                        'after' => $directAfterWithdrawable,
                        'memo' => '【一级】直推佣金（撮合）：' . $itemTitle . '（利润：' . number_format($profit, 2) . '元，比例：' . ($directRate * 100) . '%）',
                        'create_time' => $now,
                    ]);

                    // 记录活动日志
                    Db::name('user_activity_log')->insert([
                        'user_id' => $directInviterId,
                        'related_user_id' => $sellerId,
                        'action_type' => 'agent_direct_commission',
                        'change_field' => 'withdrawable_money',
                        'change_value' => (string)$directCommission,
                        'before_value' => (string)$directBeforeWithdrawable,
                        'after_value' => (string)$directAfterWithdrawable,
                        'remark' => '【一级】直推佣金（撮合）：' . $itemTitle . '（利润：' . number_format($profit, 2) . '元，比例：' . ($directRate * 100) . '%）',
                        'extra' => json_encode([
                            'level' => 1,
                            'seller_id' => $sellerId,
                            'profit' => $profit,
                            'commission_rate' => $directRate,
                            'commission_amount' => $directCommission,
                            'consignment_id' => $consignmentId,
                            'order_no' => $orderNo,
                            'order_id' => $orderId,
                            'item_title' => $itemTitle,
                        ], JSON_UNESCAPED_UNICODE),
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    
                    $output->writeln("    代理佣金：直推（用户ID {$directInviterId}）获得 {$directCommission} 元");
                }
            }
        }

        // 2. 间推佣金：获取直推的邀请人（间推）
        if ($directInviter && $directInviterId > 0) {
            $indirectInviterId = (int)($directInviter['inviter_id'] ?? 0);
            if ($indirectInviterId > 0) {
                $indirectInviter = Db::name('user')
                    ->where('id', $indirectInviterId)
                    ->lock(true)
                    ->find();
                
                if ($indirectInviter) {
                    $indirectCommission = round($profit * $indirectRate, 2);
                    if ($indirectCommission > 0) {
                        // 修复：间推佣金发放到可提现余额
                        $indirectBeforeWithdrawable = (float)$indirectInviter['withdrawable_money'];
                        $indirectAfterWithdrawable = round($indirectBeforeWithdrawable + $indirectCommission, 2);
                        
                        Db::name('user')
                            ->where('id', $indirectInviterId)
                            ->update([
                                'withdrawable_money' => $indirectAfterWithdrawable,
                                'update_time' => $now,
                            ]);

                        // 记录可提现余额变动日志
                        $flowNo = generateSJSFlowNo($indirectInviterId);
                        $batchNo = generateBatchNo('MATCHING_COMMISSION', $orderId);
                        Db::name('user_money_log')->insert([
                            'user_id' => $indirectInviterId,
                            'flow_no' => $flowNo,
                            'batch_no' => $batchNo,
                            'biz_type' => 'matching_commission',
                            'biz_id' => $orderId,
                            'field_type' => 'withdrawable_money', // 可提现余额变动
                            'money' => $indirectCommission,
                            'before' => $indirectBeforeWithdrawable,
                            'after' => $indirectAfterWithdrawable,
                            'memo' => '【二级】间推佣金（撮合）：' . $itemTitle . '（利润：' . number_format($profit, 2) . '元，比例：' . ($indirectRate * 100) . '%）',
                            'create_time' => $now,
                        ]);

                        // 记录活动日志
                        Db::name('user_activity_log')->insert([
                            'user_id' => $indirectInviterId,
                            'related_user_id' => $sellerId,
                            'action_type' => 'agent_indirect_commission',
                            'change_field' => 'withdrawable_money',
                            'change_value' => (string)$indirectCommission,
                            'before_value' => (string)$indirectBeforeWithdrawable,
                            'after_value' => (string)$indirectAfterWithdrawable,
                            'remark' => '【二级】间推佣金（撮合）：' . $itemTitle . '（利润：' . number_format($profit, 2) . '元，比例：' . ($indirectRate * 100) . '%）',
                            'extra' => json_encode([
                                'level' => 2,
                                'seller_id' => $sellerId,
                                'profit' => $profit,
                                'commission_rate' => $indirectRate,
                                'commission_amount' => $indirectCommission,
                                'consignment_id' => $consignmentId,
                                'order_no' => $orderNo,
                                'order_id' => $orderId,
                                'item_title' => $itemTitle,
                            ], JSON_UNESCAPED_UNICODE),
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);
                        
                        $output->writeln("    代理佣金：间推（用户ID {$indirectInviterId}）获得 {$indirectCommission} 元");
                    }
                }
            }
        }

        // 3. 代理团队奖（累计制+同级特殊处理）：向上查找所有代理，按等级分配团队奖
        // 累计制：1级(9%) -> 2级(12%) -> 3级(15%) -> 4级(18%) -> 5级(21%)
        // 级差分配：1级拿9%，2级拿12%-9%=3%，3级拿15%-12%=3%，以此类推
        // 同级特殊处理：如果上级和下级是同一等级的代理，上级只拿10%的同级奖
        // 假设 user_type >= 3 表示代理，3=1级，4=2级，5=3级，6=4级，7=5级
        
        // 向上查找所有代理（最多向上查找10层），记录每个代理的等级和ID
        $agentChain = []; // [['user_id' => xxx, 'agent_level' => xxx], ...]
        $searchUserId = $sellerId;
        
        for ($searchDepth = 0; $searchDepth < 10; $searchDepth++) {
            $searchUser = Db::name('user')
                ->where('id', $searchUserId)
                ->find();
            
            if (!$searchUser) {
                break;
            }
            
            $inviterId = (int)$searchUser['inviter_id'];
            if ($inviterId <= 0) {
                break;
            }
            
            $inviter = Db::name('user')
                ->where('id', $inviterId)
                ->find();
            
            if (!$inviter) {
                break;
            }
            
            // 检查是否是代理（user_type >= 3 表示代理，3=1级，4=2级，5=3级，6=4级，7=5级）
            $agentLevel = (int)$inviter['user_type'] - 2; // user_type 3->1级, 4->2级, 5->3级, 6->4级, 7->5级
            
            if ($agentLevel >= 1 && $agentLevel <= 5) {
                $agentChain[] = [
                    'user_id' => $inviterId,
                    'agent_level' => $agentLevel,
                ];
            }
            
            $searchUserId = $inviterId;
        }
        
        // 按等级分组，记录每个等级第一次出现的代理
        $foundAgents = []; // [agentLevel => agentId]
        foreach ($agentChain as $agent) {
            $level = $agent['agent_level'];
            if (!isset($foundAgents[$level])) {
                $foundAgents[$level] = $agent['user_id'];
            }
        }
        
        // 按等级从低到高分配团队奖（累计制+同级特殊处理）
        $previousRate = 0;
        $previousLevel = 0;
        
        for ($level = 1; $level <= 5; $level++) {
            if (!isset($foundAgents[$level])) {
                continue; // 没找到该等级的代理，跳过
            }
            
            $agentId = $foundAgents[$level];
            
            // 判断是否是同级代理
            $isSameLevel = ($level == $previousLevel);
            
            if ($isSameLevel) {
                // 同级代理：只拿10%的同级奖
                $actualRate = $sameLevelRate;
                $commissionType = '同级奖';
            } else {
                // 不同级代理：按累计级差分配
                $currentRate = $teamRates[$level] ?? 0;
                $actualRate = $currentRate - $previousRate; // 级差：当前等级比例 - 上一等级比例
                $commissionType = '层级奖';
                $previousRate = $currentRate; // 更新上一等级的累计比例
            }
            
            $previousLevel = $level; // 更新上一个代理的等级
            
            if ($actualRate > 0) {
                $teamCommission = round($profit * $actualRate, 2);
                
                if ($teamCommission > 0) {
                    $agent = Db::name('user')
                        ->where('id', $agentId)
                        ->lock(true)
                        ->find();
                    
                    if ($agent) {
                        // 修复：代理团队奖发放到可提现余额
                        $teamBeforeWithdrawable = (float)$agent['withdrawable_money'];
                        $teamAfterWithdrawable = round($teamBeforeWithdrawable + $teamCommission, 2);
                        
                        Db::name('user')
                            ->where('id', $agentId)
                            ->update([
                                'withdrawable_money' => $teamAfterWithdrawable,
                                'update_time' => $now,
                            ]);

                        // 记录可提现余额变动日志
                        Db::name('user_money_log')->insert([
                            'user_id' => $agentId,
                            'money' => $teamCommission,
                            'before' => $teamBeforeWithdrawable,
                            'after' => $teamAfterWithdrawable,
                            'memo' => "{$level}级代理团队奖（{$commissionType}·撮合）：{$itemTitle}（利润：" . number_format($profit, 2) . "元，比例：" . ($actualRate * 100) . "%）",
                            'create_time' => $now,
                        ]);

                        // 记录活动日志
                        Db::name('user_activity_log')->insert([
                            'user_id' => $agentId,
                            'related_user_id' => $sellerId,
                            'action_type' => 'agent_team_commission',
                            'change_field' => 'withdrawable_money',
                            'change_value' => (string)$teamCommission,
                            'before_value' => (string)$teamBeforeWithdrawable,
                            'after_value' => (string)$teamAfterWithdrawable,
                            'remark' => "{$level}级代理团队奖（{$commissionType}·撮合）：{$itemTitle}（利润：" . number_format($profit, 2) . "元，比例：" . ($actualRate * 100) . "%）",
                            'extra' => json_encode([
                                'seller_id' => $sellerId,
                                'profit' => $profit,
                                'agent_level' => $level,
                                'commission_rate' => $actualRate,
                                'commission_type' => $commissionType,
                                'is_same_level' => $isSameLevel,
                                'commission_amount' => $teamCommission,
                                'consignment_id' => $consignmentId,
                                'order_no' => $orderNo,
                                'order_id' => $orderId,
                                'item_title' => $itemTitle,
                            ], JSON_UNESCAPED_UNICODE),
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);
                        
                        $output->writeln("    代理佣金：{$level}级团队奖·{$commissionType}（用户ID {$agentId}）获得 {$teamCommission} 元");
                    }
                }
            }
        }
    }

    /**
     * 自动下架场次结束后的寄售订单
     */
    protected function autoOffShelfConsignments(Output $output, int $now, string $currentTime): void
    {
        $output->writeln('[' . date('Y-m-d H:i:s') . '] 开始检查场次结束需下架的寄售订单...');
        
        try {
            // 查询所有已结束的场次
            $endedSessions = Db::name('collection_session')
                ->where('status', '1') // 启用中的场次
                ->select()
                ->toArray();
            
            $offShelfCount = 0;
            $freeAttemptsCount = 0; // 记录增加免费寄售次数的数量
            
            foreach ($endedSessions as $session) {
                $sessionId = $session['id'];
                $startTime = $session['start_time'] ?? '00:00';
                $endTime = $session['end_time'] ?? '23:59';
                
                // 判断场次是否已结束
                $isEnded = false;
                if ($endTime < $startTime) {
                    // 跨天场次：当前时间不在 [start, 23:59] 和 [00:00, end] 区间内
                    $isEnded = !($currentTime >= $startTime || $currentTime <= $endTime);
                } else {
                    // 普通场次：当前时间超过结束时间
                    $isEnded = $currentTime > $endTime;
                }
                
                if (!$isEnded) {
                    continue; // 场次未结束，跳过
                }
                
                // 查询该场次下所有寄售中的订单
                $consignments = Db::name('collection_consignment')
                    ->alias('c')
                    ->leftJoin('asset_package ap', 'c.package_id = ap.id')
                    ->where('ap.session_id', $sessionId)
                    ->where('c.status', 1) // 寄售中
                    ->field('c.*')
                    ->select()
                    ->toArray();
                
                if (empty($consignments)) {
                    continue; // 该场次无寄售订单
                }
                
                $output->writeln("  场次 #{$sessionId} 「{$session['title']}」已结束（{$startTime}-{$endTime}），自动下架 " . count($consignments) . " 个寄售订单");
                
                // 批量下架并退回寄售券
                foreach ($consignments as $consignment) {
                    try {
                        Db::startTrans();
                        
                        // 更新寄售状态为已下架（status=3）
                        Db::name('collection_consignment')
                            ->where('id', $consignment['id'])
                            ->update([
                                'status' => 3, // 3=已下架
                                'update_time' => $now,
                            ]);
                        
                        // 更新用户藏品状态为未寄售，并增加免费寄售次数
                        Db::name('user_collection')
                            ->where('id', $consignment['user_collection_id'])
                            ->update([
                                'consignment_status' => 0, // 0=未寄售
                                'free_consign_attempts' => Db::raw('free_consign_attempts + 1'), // 增加一次免费寄售次数
                                'update_time' => $now,
                            ]);
                        
                        $freeAttemptsCount++; // 计数增加免费次数的数量
                        
                        // 记录活动日志
                        Db::name('user_activity_log')->insert([
                            'user_id' => $consignment['user_id'],
                            'related_user_id' => 0,
                            'action_type' => 'consignment_offshelf',
                            'change_field' => 'consignment_status',
                            'change_value' => '0',
                            'before_value' => '1',
                            'after_value' => '0',
                            'remark' => "场次结束自动下架寄售订单（场次#{$sessionId}，寄售ID#{$consignment['id']}），已增加一次免费寄售次数",
                            'extra' => json_encode([
                                'consignment_id' => $consignment['id'],
                                'session_id' => $sessionId,
                                'session_title' => $session['title'],
                                'package_id' => $consignment['package_id'],
                                'price' => $consignment['price'],
                                'reason' => 'session_ended',
                                'compensation' => 'free_consign_attempt', // 补偿方式：免费寄售次数
                            ], JSON_UNESCAPED_UNICODE),
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);
                        
                        Db::commit();
                        $offShelfCount++;
                        
                    } catch (\Throwable $e) {
                        Db::rollback();
                        $output->writeln("    ✗ 下架寄售订单 #{$consignment['id']} 失败: " . $e->getMessage());
                    }
                }
            }
            
            if ($offShelfCount > 0) {
                $output->writeln("场次结束自动下架完成！共下架 {$offShelfCount} 个寄售订单，增加 {$freeAttemptsCount} 次免费寄售机会");
            } else {
                $output->writeln('暂无需要下架的寄售订单');
            }
            
        } catch (\Throwable $e) {
            $output->writeln('自动下架寄售订单失败: ' . $e->getMessage());
        }
        
        $output->writeln('');
    }
}

