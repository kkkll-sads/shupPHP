<?php
/**
 * 添加用户持仓管理菜单规则
 * 执行方式：php add_user_collection_menu.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\common\library\Menu;
use think\facade\Db;

echo "=== 添加用户持仓管理菜单 ===\n\n";

// 查找藏品管理菜单目录
$collectionMenu = Db::name('admin_rule')
    ->where('name', 'collection')
    ->where('type', 'menu_dir')
    ->find();

if (!$collectionMenu) {
    echo "❌ 错误：未找到藏品管理菜单目录\n";
    exit(1);
}

echo "✓ 找到藏品管理菜单目录，ID: {$collectionMenu['id']}\n\n";

// 检查菜单是否已存在
$existing = Db::name('admin_rule')
    ->where('name', 'collection/userCollection')
    ->find();

if ($existing) {
    echo "⚠️  用户持仓管理菜单已存在，ID: {$existing['id']}\n";
    echo "   标题: {$existing['title']}\n";
    echo "   状态: " . ($existing['status'] == 1 ? '启用' : '禁用') . "\n\n";
    
    // 如果已存在但被禁用，则启用它
    if ($existing['status'] == 0) {
        echo "正在启用菜单...\n";
        Db::name('admin_rule')
            ->where('id', $existing['id'])
            ->update([
                'status' => 1,
                'update_time' => time(),
            ]);
        echo "✓ 菜单已启用\n\n";
    }
    
    // 检查并创建按钮权限
    $buttonMenus = [
        [
            'type' => 'button',
            'title' => '查看',
            'name' => 'collection/userCollection/index',
            'path' => '',
            'icon' => '',
            'component' => '',
            'keepalive' => 0,
            'extend' => 'none',
            'remark' => '用户持仓管理 - 查看列表',
            'weigh' => 0,
            'status' => 1,
        ],
        [
            'type' => 'button',
            'title' => '详情',
            'name' => 'collection/userCollection/detail',
            'path' => '',
            'icon' => '',
            'component' => '',
            'keepalive' => 0,
            'extend' => 'none',
            'remark' => '用户持仓管理 - 查看详情',
            'weigh' => 0,
            'status' => 1,
        ],
        [
            'type' => 'button',
            'title' => '用户统计',
            'name' => 'collection/userCollection/userStats',
            'path' => '',
            'icon' => '',
            'component' => '',
            'keepalive' => 0,
            'extend' => 'none',
            'remark' => '用户持仓管理 - 用户统计',
            'weigh' => 0,
            'status' => 1,
        ],
        [
            'type' => 'button',
            'title' => '转矿机',
            'name' => 'collection/userCollection/toMining',
            'path' => '',
            'icon' => '',
            'component' => '',
            'keepalive' => 0,
            'extend' => 'none',
            'remark' => '用户持仓管理 - 转为矿机',
            'weigh' => 0,
            'status' => 1,
        ],
    ];
    
    echo "检查按钮权限...\n";
    $createdButtons = 0;
    foreach ($buttonMenus as $buttonMenu) {
        $existingButton = Db::name('admin_rule')
            ->where('name', $buttonMenu['name'])
            ->find();
        
        if (!$existingButton) {
            try {
                Menu::create([$buttonMenu], $existing['id'], 'ignore', 'backend');
                $createdButtons++;
                echo "  ✓ 创建按钮权限: {$buttonMenu['title']}\n";
            } catch (\Throwable $e) {
                echo "  ❌ 创建按钮权限失败: {$buttonMenu['title']} - {$e->getMessage()}\n";
            }
        } else {
            echo "  ⊙ 按钮权限已存在: {$buttonMenu['title']}\n";
        }
    }
    
    if ($createdButtons > 0) {
        echo "\n✓ 按钮权限创建成功（共 {$createdButtons} 个）\n";
    }
    
    echo "\n✅ 菜单和权限检查完成！\n";
    exit(0);
}

// 获取最大权重值，确保新菜单排在最后
$maxWeigh = Db::name('admin_rule')
    ->where('pid', $collectionMenu['id'])
    ->where('type', 'menu')
    ->max('weigh');

$newWeigh = ($maxWeigh ?: 0) + 1;

// 定义菜单数据
$menu = [
    [
        'type' => 'menu',
        'title' => '用户持仓管理',
        'name' => 'collection/userCollection',
        'path' => 'collection/userCollection',
        'icon' => 'fa fa-user-circle',
        'menu_type' => 'tab',
        'component' => '/src/views/backend/collection/userCollection/index.vue',
        'keepalive' => 0,
        'extend' => 'none',
        'remark' => '用户持仓管理 - 查看和管理用户持有的藏品',
        'weigh' => $newWeigh,
        'status' => 1,
    ],
    // 添加按钮权限
    [
        'type' => 'button',
        'title' => '查看',
        'name' => 'collection/userCollection/index',
        'path' => '',
        'icon' => '',
        'component' => '',
        'keepalive' => 0,
        'extend' => 'none',
        'remark' => '用户持仓管理 - 查看列表',
        'weigh' => 0,
        'status' => 1,
    ],
    [
        'type' => 'button',
        'title' => '详情',
        'name' => 'collection/userCollection/detail',
        'path' => '',
        'icon' => '',
        'component' => '',
        'keepalive' => 0,
        'extend' => 'none',
        'remark' => '用户持仓管理 - 查看详情',
        'weigh' => 0,
        'status' => 1,
    ],
    [
        'type' => 'button',
        'title' => '用户统计',
        'name' => 'collection/userCollection/userStats',
        'path' => '',
        'icon' => '',
        'component' => '',
        'keepalive' => 0,
        'extend' => 'none',
        'remark' => '用户持仓管理 - 用户统计',
        'weigh' => 0,
        'status' => 1,
    ],
    [
        'type' => 'button',
        'title' => '转矿机',
        'name' => 'collection/userCollection/toMining',
        'path' => '',
        'icon' => '',
        'component' => '',
        'keepalive' => 0,
        'extend' => 'none',
        'remark' => '用户持仓管理 - 转为矿机',
        'weigh' => 0,
        'status' => 1,
    ],
];

try {
    // 创建菜单（第一个是主菜单，后面的作为子菜单）
    $mainMenu = $menu[0];
    $buttonMenus = array_slice($menu, 1);
    
    // 先创建主菜单
    Menu::create([$mainMenu], $collectionMenu['id'], 'ignore', 'backend');
    
    // 获取刚创建的主菜单ID
    $mainMenuRecord = Db::name('admin_rule')
        ->where('name', 'collection/userCollection')
        ->where('type', 'menu')
        ->find();
    
    if ($mainMenuRecord) {
        echo "✓ 主菜单创建成功，ID: {$mainMenuRecord['id']}\n";
        
        // 检查并创建按钮权限
        $createdButtons = 0;
        foreach ($buttonMenus as $buttonMenu) {
            $existingButton = Db::name('admin_rule')
                ->where('name', $buttonMenu['name'])
                ->find();
            
            if (!$existingButton) {
                Menu::create([$buttonMenu], $mainMenuRecord['id'], 'ignore', 'backend');
                $createdButtons++;
                echo "  ✓ 创建按钮权限: {$buttonMenu['title']}\n";
            } else {
                echo "  ⊙ 按钮权限已存在: {$buttonMenu['title']}\n";
            }
        }
        
        if ($createdButtons > 0) {
            echo "\n✓ 按钮权限创建成功（共 {$createdButtons} 个）\n";
        }
        
        echo "\n=== 菜单创建完成 ===\n";
        echo "菜单名称: {$mainMenuRecord['title']}\n";
        echo "菜单路径: {$mainMenuRecord['path']}\n";
        echo "组件路径: {$mainMenuRecord['component']}\n";
        echo "父级菜单: 藏品管理 (ID: {$collectionMenu['id']})\n\n";
        
        echo "✅ 用户持仓管理菜单已成功添加到菜单规则管理中！\n";
        echo "   请刷新后台管理页面查看新菜单。\n";
    } else {
        echo "❌ 错误：主菜单创建失败\n";
        exit(1);
    }
} catch (\Throwable $e) {
    echo "❌ 错误：{$e->getMessage()}\n";
    echo "   文件: {$e->getFile()}\n";
    echo "   行号: {$e->getLine()}\n";
    exit(1);
}
