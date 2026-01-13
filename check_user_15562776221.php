<?php
/**
 * 检查用户 15562776221 的账户情况
 */

require __DIR__ . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;
use app\common\service\UserService;

$mobile = '15562776221';

echo "=== 账户分析报告：{$mobile} ===\n\n";

$user = Db::name('user')->where('mobile', $mobile)->find();
if (!$user) {
    echo "❌ 用户不存在\n";
    exit(1);
}

$userId = $user['id'];

echo "【用户基本信息】\n";
echo "  用户ID: {$userId}\n";
echo "  手机号: {$user['mobile']}\n";
echo "  昵称: " . ($user['nickname'] ?? '') . "\n";
echo "  用户类型: " . ($user['user_type'] ?? 0) . " (0=普通,1=交易,2=高级交易)\n";
echo "  注册时间: " . date('Y-m-d H:i:s', $user['create_time']) . "\n\n";

// 今日购买统计
$todayOrders = Db::name('collection_order')
    ->where('user_id', $userId)
    ->where('create_time', '>=', strtotime('today'))
    ->count();

$todayAmount = Db::name('collection_order')
    ->where('user_id', $userId)
    ->where('create_time', '>=', strtotime('today'))
    ->sum('total_amount');

echo "【今日购买统计】\n";
echo "  订单数: {$todayOrders}\n";
echo "  总金额: " . round($todayAmount, 2) . " 元\n\n";

// 藏品统计
$totalCollections = Db::name('user_collection')
    ->where('user_id', $userId)
    ->count();

$todayCollections = Db::name('user_collection')
    ->where('user_id', $userId)
    ->where('buy_time', '>=', strtotime('today'))
    ->count();

$statusStats = Db::name('user_collection')
    ->where('user_id', $userId)
    ->field('consignment_status, COUNT(*) as count')
    ->group('consignment_status')
    ->select();

$statusMap = [0 => '未寄售', 1 => '寄售中', 2 => '已售出'];

echo "【藏品统计】\n";
echo "  总藏品数: {$totalCollections}\n";
echo "  今日购买: {$todayCollections}\n";
echo "  状态分布:\n";
foreach ($statusStats as $stat) {
    echo "    " . ($statusMap[$stat['consignment_status']] ?? '未知') . ": {$stat['count']}\n";
}
echo "\n";

// 20点购买的藏品详情
echo "【20点购买的藏品详情】\n";
$collections20 = Db::name('user_collection')
    ->alias('uc')
    ->leftJoin('collection_item ci', 'uc.item_id = ci.id')
    ->leftJoin('collection_session cs', 'ci.session_id = cs.id')
    ->leftJoin('price_zone_config pz', 'ci.zone_id = pz.id')
    ->where('uc.user_id', $userId)
    ->where('uc.buy_time', '>=', strtotime('2026-01-13 20:00:00'))
    ->where('uc.buy_time', '<', strtotime('2026-01-13 20:01:00'))
    ->field('uc.id, uc.title, uc.price, uc.consignment_status, ci.zone_id, ci.session_id, pz.name as zone_name, cs.title as session_title')
    ->order('uc.id asc')
    ->select();

$collections20Array = $collections20->toArray();
echo "  20点购买总数: " . count($collections20Array) . "\n";
echo "  已寄售并售出: " . count(array_filter($collections20Array, fn($c) => $c['consignment_status'] == 2)) . "\n";
echo "  未寄售: " . count(array_filter($collections20Array, fn($c) => $c['consignment_status'] == 0)) . "\n\n";

// 按价格区间分组
$zoneGroups = [];
foreach ($collections20Array as $c) {
    $zoneId = $c['zone_id'];
    if (!isset($zoneGroups[$zoneId])) {
        $zoneGroups[$zoneId] = [
            'zone_name' => $c['zone_name'],
            'zone_id' => $zoneId,
            'total' => 0,
            'sold' => 0,
            'unsold' => 0,
        ];
    }
    $zoneGroups[$zoneId]['total']++;
    if ($c['consignment_status'] == 2) {
        $zoneGroups[$zoneId]['sold']++;
    } else {
        $zoneGroups[$zoneId]['unsold']++;
    }
}

echo "  按价格区间分组:\n";
foreach ($zoneGroups as $zoneId => $group) {
    echo "    {$group['zone_name']} (ID: {$zoneId}): 总数={$group['total']}, 已售={$group['sold']}, 未寄售={$group['unsold']}\n";
}
echo "\n";

// 寄售券情况
$now = time();
$coupons = Db::name('user_consignment_coupon')
    ->where('user_id', $userId)
    ->where('status', 1)
    ->where('expire_time', '>', $now)
    ->field('id, session_id, zone_id, expire_time')
    ->select();

echo "【寄售券情况】\n";
echo "  可用寄售券总数: " . count($coupons) . "\n";

// 按场次和区间分组寄售券
$couponsArray = $coupons->toArray();
$couponGroups = [];
foreach ($couponsArray as $c) {
    $key = $c['session_id'] . '_' . $c['zone_id'];
    if (!isset($couponGroups[$key])) {
        $zone = Db::name('price_zone_config')->where('id', $c['zone_id'])->find();
        $session = Db::name('collection_session')->where('id', $c['session_id'])->find();
        $couponGroups[$key] = [
            'session_id' => $c['session_id'],
            'session_title' => $session['title'] ?? '未知',
            'zone_id' => $c['zone_id'],
            'zone_name' => $zone['name'] ?? '未知',
            'count' => 0,
        ];
    }
    $couponGroups[$key]['count']++;
}

echo "  按场次和区间分组:\n";
foreach ($couponGroups as $key => $group) {
    echo "    {$group['session_title']} - {$group['zone_name']} (场次ID: {$group['session_id']}, 区间ID: {$group['zone_id']}): {$group['count']} 张\n";
}
echo "\n";

// 检查未寄售藏品是否有匹配的寄售券
echo "【未寄售藏品与寄售券匹配分析】\n";
$unsoldCollections = array_filter($collections20Array, fn($c) => $c['consignment_status'] == 0);

foreach ($unsoldCollections as $c) {
    echo "  藏品ID: {$c['id']} | 价格: {$c['price']} | 场次: {$c['session_title']} (ID: {$c['session_id']}) | 区间: {$c['zone_name']} (ID: {$c['zone_id']})\n";
    
    // 检查是否有匹配的寄售券
    $matchingCoupons = array_filter($couponsArray, function($coupon) use ($c) {
        return $coupon['session_id'] == $c['session_id'] && $coupon['zone_id'] == $c['zone_id'];
    });
    
    if (count($matchingCoupons) > 0) {
        echo "    ✓ 有匹配的寄售券: " . count($matchingCoupons) . " 张\n";
    } else {
        echo "    ❌ 没有完全匹配的寄售券\n";
        
        // 检查跨区寄售券（根据当前设置，可以跨5个区）
        $availableCoupon = UserService::getAvailableCouponForConsignment(
            $userId,
            $c['session_id'],
            $c['zone_id'],
            $c['price']
        );
        
        if ($availableCoupon) {
            echo "    ✓ 有可用的跨区寄售券: ID={$availableCoupon['id']}, 场次ID={$availableCoupon['session_id']}, 区间ID={$availableCoupon['zone_id']}\n";
        } else {
            echo "    ❌ 没有可用的跨区寄售券\n";
        }
    }
    echo "\n";
}

echo "【结论】\n";
$canConsignCount = 0;
foreach ($unsoldCollections as $c) {
    $availableCoupon = UserService::getAvailableCouponForConsignment(
        $userId,
        $c['session_id'],
        $c['zone_id'],
        $c['price']
    );
    if ($availableCoupon) {
        $canConsignCount++;
    }
}

echo "  未寄售藏品数: " . count($unsoldCollections) . "\n";
echo "  可以寄售的藏品数: {$canConsignCount}\n";
echo "  无法寄售的藏品数: " . (count($unsoldCollections) - $canConsignCount) . "\n";

if ($canConsignCount < count($unsoldCollections)) {
    echo "\n⚠️  部分藏品无法寄售，可能原因：\n";
    echo "  1. 寄售券场次不匹配\n";
    echo "  2. 寄售券价格区间不匹配（即使允许跨5个区）\n";
    echo "  3. 寄售券已用完\n";
}
