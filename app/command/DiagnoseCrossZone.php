<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use app\common\service\core\MarketService;
use app\admin\model\CollectionItem;

class DiagnoseCrossZone extends Command
{
    protected function configure()
    {
        $this->setName('diagnose:zone')->setDescription('Diagnose cross-zone pricing issues');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln("Starting diagnostics...");
        $items = CollectionItem::where('status', 'normal')->select(); // Assuming status 'normal' or check 1? Model says 'status' property.
        // CollectionItem model doesn't show status constants, but usually 1 is normal. 
        // Or remove status check to scan all.
        // Let's check all items that have stock or consignments.
        
        $items = Db::name('collection_item')->select();
        $output->writeln("Scanning " . count($items) . " items...");
        
        $count = 0;
        foreach ($items as $item) {
             $itemId = $item['id'];
             $zoneId = $item['zone_id'] ?? 0;
             $output->writeln("Checking Item #{$itemId} ZoneID: {$zoneId}");
             $packageId = $item['package_id'] ?? 0;
             
             if ($zoneId <= 0) continue;
             
             $zone = Db::name('price_zone_config')->where('id', $zoneId)->find();
             if (!$zone) continue;
             
             $zoneName = $zone['name'];
             $zoneMin = (float)$zone['min_price'];
             $zoneMax = (float)$zone['max_price'];
             
             // Check consignments
             $consignments = Db::name('collection_consignment')
                 ->where('item_id', $itemId)
                 ->whereIn('status', [1, 2]) // 1 = Consigning, 2 = Sold
                 ->select();
                 
             foreach ($consignments as $c) {
                 $price = (float)$c['price'];
                 
                 // Check if price is outside zone [min, max)
                 if ($price < $zoneMin || ($zoneMax > 0 && $price >= $zoneMax)) {
                     // Determine actual zone
                     $actualZoneId = MarketService::getZoneIdByPrice($packageId, $price);
                     $actualZoneName = 'Unknown';
                     if ($actualZoneId) {
                         $z = Db::name('price_zone_config')->where('id', $actualZoneId)->find();
                         $actualZoneName = $z['name'];
                     } else {
                         // Maybe price is 0 or above max?
                         $actualZoneName = MarketService::getPriceZoneName($price);
                     }
                     
                     $output->writeln("MISMATCH: Item #{$itemId} '{$item['title']}' is in Zone [{$zoneName} aka {$zoneMin}-{$zoneMax}]. Consignment #{$c['id']} Price: {$price} belongs to [{$actualZoneName}].");
                     $count++;
                 }
             }
        }
        $output->writeln("Found {$count} mismatches.");
    }
}
