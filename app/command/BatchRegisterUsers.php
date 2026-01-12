<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

// 引入必要的函数
if (!function_exists('hash_password')) {
    function hash_password($password) {
        return md5($password . 'think');
    }
}

if (!function_exists('generateSJSFlowNo')) {
    function generateSJSFlowNo($userId) {
        return 'SJS' . date('YmdHis') . str_pad((string)$userId, 6, '0', STR_PAD_LEFT) . rand(100, 999);
    }
}

if (!function_exists('generateBatchNo')) {
    function generateBatchNo($prefix, $id) {
        return $prefix . '_' . date('Ymd') . '_' . str_pad((string)$id, 8, '0', STR_PAD_LEFT);
    }
}

/**
 * 批量注册用户、实名、添加余额、预约撮合
 */
class BatchRegisterUsers extends Command
{
    protected function configure()
    {
        $this->setName('batch:register-users')
            ->setDescription('批量注册用户、实名、添加余额、预约撮合')
            ->addArgument('invite_code', \think\console\input\Argument::REQUIRED, '邀请码')
            ->addArgument('count', \think\console\input\Argument::REQUIRED, '注册数量')
            ->addOption('balance', 'b', \think\console\input\Option::VALUE_OPTIONAL, '可用余额', '10000')
            ->addOption('session_id', 's', \think\console\input\Option::VALUE_OPTIONAL, '场次ID', '0')
            ->addOption('zone_id', 'z', \think\console\input\Option::VALUE_OPTIONAL, '价格分区ID', '0')
            ->addOption('package_id', 'p', \think\console\input\Option::VALUE_OPTIONAL, '资产包ID', '0');
    }

    protected function execute(Input $input, Output $output)
    {
        $inviteCode = $input->getArgument('invite_code');
        $count = (int)$input->getArgument('count');
        $balance = (float)$input->getOption('balance');
        $sessionId = (int)$input->getOption('session_id');
        $zoneId = (int)$input->getOption('zone_id');
        $packageId = (int)$input->getOption('package_id');

        $output->writeln("开始批量注册用户...");
        $output->writeln("邀请码: {$inviteCode}");
        $output->writeln("注册数量: {$count}");
        $output->writeln("可用余额: {$balance}");
        $output->writeln("场次ID: {$sessionId}");
        $output->writeln("价格分区ID: {$zoneId}");
        $output->writeln("资产包ID: {$packageId}");
        $output->writeln("");

        // 1. 验证邀请码
        $inviteCodeInfo = Db::name('invite_code')->where('code', $inviteCode)->find();
        if (!$inviteCodeInfo) {
            // 尝试从用户表查找
            $inviter = Db::name('user')->where('invite_code', $inviteCode)->where('status', 'enable')->find();
            if (!$inviter) {
                $output->writeln("❌ 邀请码不存在: {$inviteCode}");
                return;
            }
            $inviterId = $inviter['id'];
        } else {
            $inviterId = $inviteCodeInfo['user_id'];
        }

        $output->writeln("✓ 邀请码验证通过，邀请人ID: {$inviterId}");

        // 2. 验证场次和分区
        if ($sessionId > 0) {
            $session = Db::name('collection_session')->where('id', $sessionId)->where('status', '1')->find();
            if (!$session) {
                $output->writeln("❌ 场次不存在或未启用: {$sessionId}");
                return;
            }
            $output->writeln("✓ 场次验证通过: {$session['title']}");
        }

        if ($zoneId > 0) {
            $zone = Db::name('price_zone_config')->where('id', $zoneId)->where('status', '1')->find();
            if (!$zone) {
                $output->writeln("❌ 价格分区不存在或未启用: {$zoneId}");
                return;
            }
            $output->writeln("✓ 价格分区验证通过: {$zone['name']}");
        }

        if ($packageId > 0) {
            $package = Db::name('asset_package')->where('id', $packageId)->where('status', 1)->find();
            if (!$package) {
                $output->writeln("❌ 资产包不存在或未启用: {$packageId}");
                return;
            }
            $output->writeln("✓ 资产包验证通过: {$package['name']}");
        }

        $output->writeln("");

        // 3. 批量注册用户
        $successCount = 0;
        $failedCount = 0;
        $userIds = [];

        for ($i = 1; $i <= $count; $i++) {
            try {
                Db::startTrans();

                // 生成唯一的手机号（使用时间戳+序号）
                $mobile = '1' . str_pad((string)(time() % 1000000000), 9, '0', STR_PAD_LEFT) . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                $mobile = substr($mobile, 0, 11); // 确保11位

                // 检查手机号是否已存在
                $exists = Db::name('user')->where('mobile', $mobile)->find();
                if ($exists) {
                    $mobile = '1' . str_pad((string)(time() + $i), 10, '0', STR_PAD_LEFT);
                    $mobile = substr($mobile, 0, 11);
                }

                $username = 'm' . $mobile;
                $password = '123456';
                $payPassword = '123456';

                // 生成用户邀请码（6位随机字母数字）
                $userInviteCode = strtoupper(substr(md5(uniqid() . $mobile), 0, 6));

                // 检查用户邀请码是否重复
                while (Db::name('user')->where('invite_code', $userInviteCode)->find()) {
                    $userInviteCode = strtoupper(substr(md5(uniqid() . time()), 0, 6));
                }

                // 注册用户
                $userId = Db::name('user')->insertGetId([
                    'username' => $username,
                    'nickname' => '测试用户' . $i,
                    'mobile' => $mobile,
                    'password' => hash_password($password),
                    'pay_password' => $payPassword, // 支付密码不加密
                    'invite_code' => $userInviteCode,
                    'inviter_id' => $inviterId,
                    'status' => 'enable',
                    'balance_available' => $balance,
                    'money' => $balance, // 总资产
                    'real_name' => '测试' . $i,
                    'id_card' => '11010119900101' . str_pad((string)$i, 4, '0', STR_PAD_LEFT),
                    'real_name_status' => 2, // 已通过实名
                    'audit_time' => time(),
                    'create_time' => time(),
                    'update_time' => time(),
                ]);

                // 记录余额变动日志
                Db::name('user_money_log')->insert([
                    'user_id' => $userId,
                    'money' => $balance,
                    'before' => 0,
                    'after' => $balance,
                    'memo' => '批量注册初始余额',
                    'create_time' => time(),
                ]);

                // 记录活动日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'register',
                    'change_field' => 'balance_available',
                    'change_value' => (string)$balance,
                    'before_value' => '0',
                    'after_value' => (string)$balance,
                    'remark' => '批量注册用户，初始余额',
                    'create_time' => time(),
                    'update_time' => time(),
                ]);

                // 如果指定了场次和分区，创建预约
                if ($sessionId > 0 && $zoneId > 0 && $packageId > 0) {
                    $zone = Db::name('price_zone_config')->where('id', $zoneId)->find();
                    $freezeAmount = (float)($zone['max_price'] ?? 2000); // 使用分区最高价作为冻结金额
                    
                    // 检查用户余额是否足够
                    if ($balance >= $freezeAmount) {
                        // 计算算力（简化处理，使用固定值）
                        $baseHashrate = 10.0;
                        $totalHashrate = $baseHashrate;
                        $weight = (int)($totalHashrate * 20);

                        // 扣除冻结金额
                        $afterBalance = $balance - $freezeAmount;
                        Db::name('user')->where('id', $userId)->update([
                            'balance_available' => $afterBalance,
                            'money' => $afterBalance,
                        ]);

                        // 创建预约记录
                        $reservationId = Db::name('trade_reservations')->insertGetId([
                            'user_id' => $userId,
                            'session_id' => $sessionId,
                            'zone_id' => $zoneId,
                            'package_id' => $packageId,
                            'product_id' => 0, // 盲盒模式
                            'freeze_amount' => $freezeAmount,
                            'power_used' => $totalHashrate,
                            'base_hashrate_cost' => $baseHashrate,
                            'extra_hashrate_cost' => 0,
                            'weight' => $weight,
                            'status' => 0, // 待撮合
                            'match_order_id' => 0,
                            'match_time' => 0,
                            'create_time' => time(),
                            'update_time' => time(),
                        ]);

                        // 记录余额变动日志
                        $flowNo = generateSJSFlowNo($userId);
                        $batchNo = generateBatchNo('BLIND_BOX_RESERVE', $reservationId);
                        Db::name('user_money_log')->insert([
                            'user_id' => $userId,
                            'flow_no' => $flowNo,
                            'batch_no' => $batchNo,
                            'biz_type' => 'blind_box_reserve',
                            'biz_id' => $reservationId,
                            'field_type' => 'balance_available',
                            'money' => -$freezeAmount,
                            'before' => $balance,
                            'after' => $afterBalance,
                            'memo' => '盲盒预约冻结可用余额 - ' . ($zone['name'] ?? ''),
                            'create_time' => time(),
                        ]);

                        // 记录活动日志
                        Db::name('user_activity_log')->insert([
                            'user_id' => $userId,
                            'related_user_id' => 0,
                            'action_type' => 'blind_box_reserve',
                            'change_field' => 'balance_available',
                            'change_value' => (string)(-$freezeAmount),
                            'before_value' => (string)$balance,
                            'after_value' => (string)$afterBalance,
                            'remark' => '盲盒预约冻结 - ' . ($zone['name'] ?? ''),
                            'extra' => json_encode([
                                'session_id' => $sessionId,
                                'zone_id' => $zoneId,
                                'package_id' => $packageId,
                                'reservation_id' => $reservationId,
                            ], JSON_UNESCAPED_UNICODE),
                            'create_time' => time(),
                            'update_time' => time(),
                        ]);
                    }
                }

                Db::commit();
                $userIds[] = $userId;
                $successCount++;
                $output->writeln("✓ [{$i}/{$count}] 用户注册成功: ID={$userId}, 手机号={$mobile}, 用户名={$username}");
            } catch (\Exception $e) {
                Db::rollback();
                $failedCount++;
                $output->writeln("✗ [{$i}/{$count}] 用户注册失败: " . $e->getMessage());
            }
        }

        $output->writeln("");
        $output->writeln("========================================");
        $output->writeln("批量注册完成");
        $output->writeln("成功: {$successCount}");
        $output->writeln("失败: {$failedCount}");
        $output->writeln("用户ID列表: " . implode(', ', $userIds));
        $output->writeln("========================================");
    }
}

