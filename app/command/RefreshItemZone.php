<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

class RefreshItemZone extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('collection:refresh_zone')
            ->setDescription('Refresh item zone_id and price_zone based on current price');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('Start refreshing item zones...');

        // 获取所有启用的价格分区配置
        $zones = Db::name('price_zone_config')
            ->where('status', '1')
            ->order('min_price', 'asc')
            ->select()
            ->toArray();

        if (empty($zones)) {
            $output->writeln('No price zones configured!');
            return;
        }

        // 分批处理藏品数据
        $count = 0;
        $updated = 0;
        
        // 处理 collection_item 表
        Db::name('collection_item')->chunk(100, function ($items) use ($zones, &$count, &$updated, $output) {
            foreach ($items as $item) {
                $count++;
                $price = (float)$item['price'];
                $currentZoneId = (int)$item['zone_id'];
                $currentZoneName = $item['price_zone'];

                $matchedZone = null;
                foreach ($zones as $zone) {
                    if ($price >= $zone['min_price'] && $price <= $zone['max_price']) {
                        $matchedZone = $zone;
                        break;
                    }
                }

                if (!$matchedZone) {
                    // 如果超过最高价，且有最高分区，可以归类到最高分区（看业务逻辑，这里暂且尝试找一个max_price无限大的，或者取最大的）
                     // 简单逻辑：如果没匹配到，看是否超过最大zonemax
                     $maxZone = end($zones);
                     if ($price > $maxZone['max_price']) {
                         // 超过最大分区的也暂归为最大分区? 或者保持不变? 
                         // 这里的逻辑最好跟 CollectionItem.php 里的一致
                         // 如果 CollectionItem 逻辑是 "未找到则为0"，那这里也应该考虑到
                         // 但为了修复 "520元在500元区" 的问题，通常意味着需要更高分区
                         // 既然前面发现没匹配，说明可能没有1000元区的配置??
                         // 不，用户说有1000元区。
                         // 所以这里如果有匹配就更新。
                    }
                }

                if ($matchedZone) {
                    $newZoneId = (int)$matchedZone['id'];
                    $newZoneName = $matchedZone['name'];

                    if ($newZoneId !== $currentZoneId || $newZoneName !== $currentZoneName) {
                        Db::name('collection_item')
                            ->where('id', $item['id'])
                            ->update([
                                'zone_id' => $newZoneId,
                                'price_zone' => $newZoneName,
                                'update_time' => time(),
                            ]);
                        $updated++;
                        $output->writeln("Item {$item['id']} (Price: {$price}) updated: {$currentZoneName}[{$currentZoneId}] -> {$newZoneName}[{$newZoneId}]");
                    }
                } else {
                    $output->writeln("Item {$item['id']} (Price: {$price}) no matching zone found.");
                }
            }
        });

        $output->writeln("Refresh complete. Total checked: {$count}, Updated: {$updated}");
    }
}
