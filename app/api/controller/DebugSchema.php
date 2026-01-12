<?php
namespace app\api\controller;

use think\facade\Db;

class DebugSchema
{
    public function index()
    {
        $tables = ['collection_consignment', 'collection_order', 'collection_order_item'];
        foreach ($tables as $table) {
            echo "Table: {$table}\n";
            try {
                $columns = Db::query("SHOW COLUMNS FROM ba_{$table}");
                foreach ($columns as $col) {
                    echo " - {$col['Field']}\n";
                }
            } catch (\Exception $e) {
                echo "Error: " . $e->getMessage() . "\n";
            }
            echo "\n";
        }
    }
}

// Manually instantiate and run if executed via CLI (simplified simulation)
// In ThinkPHP environment, we might need to route to it or just use `php think` console if registered.
// Easier way: just write a raw php script that bootstraps TP or just use the existing framework structure.
// Let's overwite a test command or creating a new controller and calling it via curl locally or just reading the file.
