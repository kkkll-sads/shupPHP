<?php

namespace app\admin\controller\collection;

use app\common\controller\Backend;
use hg\apidoc\annotation as Apidoc;
use think\facade\Db;

/**
 * 藏品增值日志
 */
class ValueLog extends Backend
{

    protected array $noNeedLogin = [];
    protected array $noNeedRight = ['*']; //以此演示，实际请配置权限

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * 溯源查询
     */
    public function trace(): void
    {
        $assetCode = $this->request->param('asset_code', '');
        $itemId = $this->request->param('item_id/d', 0);

        if (empty($assetCode) && empty($itemId)) {
            $this->error('请输入确权编号或藏品ID');
        }

        // 1. 查找藏品信息
        $query = Db::name('collection_item');
        if (!empty($itemId)) {
            $query->where('id', $itemId);
        } else {
            $query->where('asset_code', $assetCode);
        }
        $item = $query->find();

        if (!$item) {
            $this->error('未找到该藏品');
        }
        
        $itemId = $item['id'];

        // 2. 溯源历史 - 拥有权流转 (User Collection)
        // 注意：由于转手后旧记录可能会被软删或转移，这里查询历史快照或关联记录
        // 假设系统逻辑：user_collection 记录当前持有。
        // 完整的交易历史在 collection_order
        // 完整的寄售历史在 collection_consignment

        // 查询所有关联的寄售记录（包括已完成、已取消、流拍）
        $consignments = Db::name('collection_consignment')
            ->alias('cc')
            ->leftJoin('user u', 'cc.user_id = u.id')
            ->where('cc.item_id', $itemId)
            ->field('cc.*, u.nickname as user_nickname, u.username as user_username')
            ->order('cc.create_time', 'asc')
            ->select()
            ->toArray();

        // 查询所有关联的订单记录（成交历史）
        $orders = Db::name('collection_order_item')
            ->alias('oi')
            ->join('collection_order o', 'oi.order_id = o.id')
            ->leftJoin('user u', 'o.user_id = u.id')
            ->where('oi.item_id', $itemId)
            ->where('o.status', 'paid')
            ->field('oi.*, o.user_id as buyer_id, o.order_no, o.create_time as order_time, u.nickname as buyer_nickname, u.username as buyer_username')
            ->order('o.create_time', 'asc')
            ->select()
            ->toArray();

        // 查询当前持有者
        $holder = Db::name('user_collection')
            ->alias('uc')
            ->leftJoin('user u', 'uc.user_id = u.id')
            ->where('uc.item_id', $itemId)
            ->field('uc.*, u.nickname as holder_nickname, u.username as holder_username')
            ->find();

        // 组装时间轴事件
        $timeline = [];

        // 事件：发行/创建
        $timeline[] = [
            'type' => 'created',
            'time' => $item['create_time'],
            'title' => '藏品发行/创建',
            'desc' => "发行价：{$item['issue_price']}",
            'price' => $item['issue_price'],
        ];

        // 事件：寄售
        foreach ($consignments as $c) {
            $statusMap = [0 => '已取消', 1 => '寄售中', 2 => '已售出', 3 => '流拍失败'];
            $statusText = $statusMap[$c['status']] ?? '未知';
            $timeline[] = [
                'type' => 'consignment',
                'time' => $c['create_time'],
                'title' => "用户发起寄售 [ID:{$c['id']}]",
                'desc' => "卖家：{$c['user_username']} (ID:{$c['user_id']}) \n寄售价：{$c['price']} \n状态：{$statusText}",
                'price' => $c['price'],
                'data' => $c
            ];
            
            // 如果是流拍，增加流拍事件
            if ($c['status'] == 3) {
                 $timeline[] = [
                    'type' => 'failed',
                    'time' => $c['update_time'] ?: $c['create_time'], // 近似时间
                    'title' => "寄售流拍/下架",
                    'desc' => "寄售记录 ID {$c['id']} 流拍",
                    'price' => $c['price'],
                ];
            }
        }

        // 事件：成交
        foreach ($orders as $o) {
            $timeline[] = [
                'type' => 'trade',
                'time' => $o['order_time'],
                'title' => "市场成交 [订单:{$o['order_no']}]",
                'desc' => "买家：{$o['buyer_username']} (ID:{$o['buyer_id']}) \n成交价：{$o['price']}",
                'price' => $o['price'],
                'data' => $o
            ];
        }

        // 按时间排序
        usort($timeline, function($a, $b) {
            return $a['time'] <=> $b['time'];
        });

        $data = [
            'item' => $item,
            'holder' => $holder,
            'timeline' => $timeline,
            'appreciation' => [
                'issue_price' => $item['issue_price'],
                'current_price' => $item['price'],
                'rate' => $item['issue_price'] > 0 ? round(($item['price'] - $item['issue_price']) / $item['issue_price'] * 100, 2) : 0,
                'value_add' => round($item['price'] - $item['issue_price'], 2)
            ]
        ];

        $this->success('', $data);
    }
}
