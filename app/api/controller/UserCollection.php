<?php

declare(strict_types=1);

namespace app\api\controller;

use think\facade\Db;
use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("我的藏品")]
class UserCollection extends Frontend
{
    protected array $noNeedLogin = [];
    protected array $noNeedRight = ['*'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("我的藏品详情"),
        Apidoc\Tag("我的藏品"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/userCollection/detail"),
        Apidoc\Query(name: "user_collection_id", type: "int", require: true, desc: "用户藏品ID"),
        Apidoc\Returned("object_type", type: "string", desc: "对象类型: user_collection"),
        Apidoc\Returned("user_collection_id", type: "int", desc: "用户藏品ID"),
        Apidoc\Returned("item_id", type: "int", desc: "商品ID"),
        Apidoc\Returned("title", type: "string", desc: "藏品标题"),
        Apidoc\Returned("image", type: "string", desc: "完整图片URL"),
        Apidoc\Returned("buy_price", type: "float", desc: "买入成本价"),
        Apidoc\Returned("market_price", type: "float", desc: "当前市场价"),
        Apidoc\Returned("asset_code", type: "string", desc: "资产编号"),
        Apidoc\Returned("hash", type: "string", desc: "唯一哈希（优先取确权哈希）"),
        Apidoc\Returned("consignment_status", type: "int", desc: "寄售状态"),
        Apidoc\Returned("consignment_status", type: "int", desc: "寄售状态"),
        Apidoc\Returned("rights_status", type: "string", desc: "确权状态"),
        Apidoc\Returned("mining_status", type: "int", desc: "矿机状态：0=否,1=是"),
        Apidoc\Returned("mining_start_time", type: "string", desc: "矿机启动时间"),
        Apidoc\Returned("expected_profit", type: "float", desc: "预期收益百分比（(当前价-买入价)/买入价*100%）"),
    ]
    public function detail(): void
    {
        $userCollectionId = $this->request->param('user_collection_id/d', 0);
        
        // 兼容旧参数名 id
        if (!$userCollectionId) {
            $userCollectionId = $this->request->param('id/d', 0);
        }

        if (!$userCollectionId) {
            $this->error('参数错误');
        }

        $userId = $this->auth->id;

        // 查询用户藏品
        $userCollection = Db::name('user_collection')
            ->alias('uc')
            ->where('uc.id', $userCollectionId)
            ->where('uc.user_id', $userId)
            ->find();

        if (!$userCollection) {
            $this->error('藏品不存在或无权限查看');
        }

        // 查询关联商品和场次信息
        $item = Db::name('collection_item')
            ->alias('i')
            ->join('collection_session s', 'i.session_id = s.id', 'LEFT')
            ->where('i.id', $userCollection['item_id'])
            ->field([
                'i.id', 'i.title', 'i.image', 'i.images', 'i.price as market_price', 'i.artist',
                'i.session_id', 'i.zone_id', 'i.price_zone', 'i.asset_code as item_asset_code', 'i.tx_hash as item_tx_hash',
                'i.contract_no as item_contract_no', 'i.rights_status as item_rights_status', 'i.block_height as item_block_height', 'i.rights_hash as item_rights_hash',
                's.title as session_title', 's.start_time as session_start_time', 's.end_time as session_end_time'
            ])
            ->find();
            
        if (!$item) {
            $this->error('关联商品已不存在');
        }

        // 交易时间判断
        $isTradingTime = false;
        if (!empty($item['session_id'])) {
             $currentTime = date('H:i');
             $isTradingTime = $this->isTimeInRange($currentTime, $item['session_start_time'] ?? '', $item['session_end_time'] ?? '');
        }

        // 哈希回退规则：rights_hash > item.tx_hash > 实时生成
        $hash = $userCollection['rights_hash'] ?? null;
        if (empty($hash)) {
            $hash = $item['item_tx_hash'] ?? null;
        }
        if (empty($hash)) {
            $hash = md5($userCollection['id'] . 'USER_COLLECTION_SALT_2025');
        }

        // 资产编号优先使用商品层的通用配置，如果商品层没配则尝试生成
        $assetCode = $item['item_asset_code'] ?? null;
        if (empty($assetCode)) {
             $assetCode = '37-DATA-' . str_pad((string)($item['session_id'] ?? 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string)$userCollectionId, 6, '0', STR_PAD_LEFT);
        }

        try {
            // 统计流拍次数
            $failCount = Db::name('collection_consignment')
                ->where('item_id', $item['id'])
                ->where('status', 3) // 3=流拍
                ->count();
                
            // 统计当前流转次数（销量）
            $transactionCount = Db::name('collection_order')
                 ->alias('o')
                 ->join('collection_order_item i', 'o.id = i.order_id')
                 ->where('i.item_id', $item['id'])
                 ->where('o.status', 'paid')
                 ->count();
        } catch (\Throwable $e) {
            // DEBUG: 获取表结构
            $debugInfo = [];
            $tables = ['collection_consignment', 'collection_order', 'collection_order_item'];
            foreach ($tables as $table) {
                try {
                    $cols = Db::query("SHOW COLUMNS FROM ba_{$table}");
                    $debugInfo[$table] = array_column($cols, 'Field');
                } catch (\Exception $ex) {
                    $debugInfo[$table] = $ex->getMessage();
                }
            }
            $this->error('DB Error: ' . $e->getMessage(), ['debug_schema' => $debugInfo]);
        }

        // 处理图片
        $image = $userCollection['image'] ? toFullUrl($userCollection['image']) : ($item['image'] ? toFullUrl($item['image']) : '');
        $images = [];
        if (!empty($item['images'])) {
            $images = array_map('toFullUrl', explode(',', $item['images']));
        }

        $data = [
            'object_type'        => 'user_collection',
            'user_collection_id' => (int)$userCollection['id'],
            'item_id'            => (int)$userCollection['item_id'],
            'title'              => $userCollection['title'] ?? $item['title'],
            'image'              => $image,
            'images'             => $images,
            'buy_price'          => (float)$userCollection['price'],
            'market_price'       => (float)$item['market_price'],
            'asset_code'         => $assetCode,
            'hash'               => $hash,
            'artist'             => $item['artist'] ?? '',
            'contract_no'        => $userCollection['contract_no'] ?: ($item['item_contract_no'] ?: ('HT-SD-2025-' . $userCollection['id'] . '-ENC')),
            'block_height'       => $userCollection['block_height'] ?: ($item['item_block_height'] ?: ('H-' . (100000 + (int)floor($userCollection['create_time'] / 60)))),
            'rights_status'      => $userCollection['rights_status'] ?: ($item['item_rights_status'] ?: '已确权锁定'),
            
            // 交易相关
            'consignment_status' => (int)($userCollection['consignment_status'] ?? 0), // 需确认字段是否存在，若不存在则为0
            'delivery_status'    => 1, // 默认为1已交付
            'fail_count'         => $failCount,
            'transaction_count'  => $transactionCount,
            
            // 场次信息
            'session_id'         => (int)$item['session_id'],
            'session_title'      => $item['session_title'] ?? '',
            'session_start_time' => $item['session_start_time'] ?? '',
            'session_end_time'   => $item['session_end_time'] ?? '',
            'is_trading_time'    => $isTradingTime,
            'zone_id'            => (int)$item['zone_id'],
            'zone_id'            => (int)$item['zone_id'],
            'price_zone'         => $item['price_zone'] ?? '',
            
            // 矿机信息
            'mining_status'      => (int)($userCollection['mining_status'] ?? 0),
            'mining_start_time'  => $userCollection['mining_start_time'] ? date('Y-m-d H:i:s', $userCollection['mining_start_time']) : '',
            'last_dividend_time' => $userCollection['last_dividend_time'] ? date('Y-m-d H:i:s', $userCollection['last_dividend_time']) : '',

            // 预期收益计算
            'expected_profit'    => $userCollection['price'] > 0 ? round((($item['market_price'] - $userCollection['price']) / $userCollection['price']) * 100, 2) : 0,
        ];

        $this->success('', $data);
    }
    
    /**
     * 判断当前时间是否在时间段内
     */
    protected function isTimeInRange($currentTime, $startTime, $endTime): bool
    {
        if (empty($startTime) || empty($endTime)) {
            return false;
        }
        return $currentTime >= $startTime && $currentTime <= $endTime;
    }
}
