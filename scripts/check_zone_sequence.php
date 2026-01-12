·<?php
/**
 * 检查 Zone ID 连续性 vs 价格连续性
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use think\facade\Db;

$app = new think\App(dirname(__DIR__));
$app->initialize();

$zones = Db::name('price_zone_config')->order('min_price', 'asc')->select()->toArray();

echo "ID\tName\t\tRange\t\tPrevID(Logic)\tPrevID(ID-1)\tMatch?\n";
echo "--------------------------------------------------------------------------------\n";

$prevZone = null;
foreach ($zones as $zone) {
    $prevIdLogic = $prevZone ? $prevZone['id'] : 'None';
    $prevIdMath = $zone['id'] - 1;
    
    $match = ($prevIdLogic == $prevIdMath) ? "YES" : "NO";
    if ($prevZone == null) $match = "-"; // First one
    
    echo "{$zone['id']}\t{$zone['name']}\t{$zone['min_price']}-{$zone['max_price']}\t{$prevIdLogic}\t\t{$prevIdMath}\t\t{$match}\n";
    
    $prevZone = $zone;
}
