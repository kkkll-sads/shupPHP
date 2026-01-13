<?php
namespace app;
require __DIR__ . '/../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App();
$app->initialize();

echo "Verifying Matching Stats Logic...\n";

// Find a session ID to test with (one that has items if possible)
$sessionId = Db::name('collection_session')->value('id');
if (!$sessionId) {
    echo "No sessions found to test.\n";
    exit;
}
echo "Testing with Session ID: $sessionId\n";

try {
    // 1. Participant Count Query
    $participantCount = Db::name('collection_matching_pool')
        ->where('session_id', $sessionId)
        ->count('DISTINCT user_id');
    echo "Participant Count: $participantCount\n";

    // 2. Package Stats Query
    $packageStats = Db::name('collection_item')
        ->alias('ci')
        ->leftJoin('asset_package cp', 'ci.package_id = cp.id')
        ->where('ci.session_id', $sessionId)
        ->field('cp.name as package_name, count(ci.id) as item_count, sum(ci.stock) as total_stock')
        ->group('ci.package_id')
        ->select();
    
    echo "Package Stats:\n";
    if (!empty($packageStats)) {
        foreach ($packageStats as $stat) {
            $packageName = $stat['package_name'] ?: '未分组';
            echo "  Package [{$packageName}]: Count {$stat['item_count']}, Stock {$stat['total_stock']}\n";
        }
    } else {
        echo "  No package stats found.\n";
    }
    
    echo "Verification Passed!\n";

} catch (\Throwable $e) {
    echo "Verification Failed: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
