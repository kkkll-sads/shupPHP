<?php

namespace app\api\controller;

use think\facade\Db;
use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;
use think\exception\HttpResponseException;
use think\facade\Log;
use app\common\service\UserService;

#[Apidoc\Title("藏品商品管理")]
class CollectionItem extends Frontend
{
    protected array $noNeedLogin = ['index', 'detail', 'originalDetail', 'bySession', 'tradeList', 'matchingPool'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("藏品商品列表"),
        Apidoc\Tag("藏品商城,商品列表"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/index"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "10"),
        Apidoc\Query(name: "session_id", type: "int", require: false, desc: "专场ID"),
        Apidoc\Returned("list", type: "array", desc: "商品列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "商品ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "商品标题"),
        Apidoc\Returned("list[].image", type: "string", desc: "商品图片完整URL"),
        Apidoc\Returned("list[].price", type: "float", desc: "价格"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
    ]
    public function index(): void
    {
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $sessionId = $this->request->param('session_id/d', 0);

        $limit = min($limit, 50); // 最大50条

        $where = [['status', '=', '1']];
        if ($sessionId) {
            $where[] = ['session_id', '=', $sessionId];
        }

        $list = Db::name('collection_item')
            ->where($where)
            ->field([
                'id',
                'session_id',
                'title',
                'image',
                'price',
                'price_zone',
                'stock',
                'sales',
            ])
            ->order('sort desc, id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 处理图片完整URL
        foreach ($list as &$item) {
            $item['image'] = $item['image'] ? full_url($item['image'], false) : '';
            $item['price'] = (float)$item['price'];
            
            // 添加价格分区信息
            if (empty($item['price_zone'])) {
                $item['price_zone'] = $this->getPriceZone($item['price']);
            }
            
            // 添加场次交易时间信息
            if (!empty($item['session_id'])) {
                $session = Db::name('collection_session')
                    ->where('id', $item['session_id'])
                    ->where('status', '1')
                    ->find();
                if ($session) {
                    $item['session_name'] = $session['title'] ?? '';
                    $item['session_start_time'] = $session['start_time'] ?? '';
                    $item['session_end_time'] = $session['end_time'] ?? '';
                    
                    // 判断当前是否在交易时间内
                    $currentTime = date('H:i');
                    $item['is_trading_time'] = $this->isTimeInRange($currentTime, $item['session_start_time'], $item['session_end_time']);
                }

        // 同时合并显示该专场的寄售中商品（若主商品未上架也要展示寄售信息）
        // 获取该专场寄售中商品（按 item 聚合，取最小寄售价）
        $consignItems = Db::name('collection_consignment')
            ->alias('c')
            ->join('collection_item i', 'c.item_id = i.id', 'LEFT')
            ->where('c.status', 1)
            ->where('i.session_id', $sessionId)
            ->field([
                'i.id',
                'i.session_id',
                'i.title',
                'i.image',
                Db::raw('MIN(c.price) AS price'),
                Db::raw('COUNT(c.id) AS stock'),
                Db::raw('0 AS sales'),
                'i.price as original_price',
                'i.session_id as session_id',
            ])
            ->group('c.item_id')
            ->select()
            ->toArray();

        // 将寄售列表合并到主列表（避免重复）
        $existsIds = array_column($list, 'id');
        foreach ($consignItems as $ci) {
            if (!in_array($ci['id'], $existsIds)) {
                $row = [
                    'id' => $ci['id'],
                    'session_id' => $ci['session_id'],
                    'title' => $ci['title'],
                    'image' => $ci['image'] ? full_url($ci['image'], false) : '',
                    'price' => (float)$ci['price'],
                    'price_zone' => null,
                    'stock' => (int)$ci['stock'],
                    'sales' => (int)$ci['sales'],
                    'session_name' => $session['title'] ?? '',
                    'session_start_time' => $session['start_time'] ?? '',
                    'session_end_time' => $session['end_time'] ?? '',
                    'is_trading_time' => $this->isTimeInRange($currentTime, $session['start_time'] ?? '', $session['end_time'] ?? ''),
                ];
                $list[] = $row;
            }
        }
            }
        }

        $total = Db::name('collection_item')
            ->where($where)
            ->count();

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[
        Apidoc\Title("藏品商品详情"),
        Apidoc\Tag("藏品商城,商品详情"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/detail"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "商品ID"),
        Apidoc\Returned("id", type: "int", desc: "商品ID"),
        Apidoc\Returned("title", type: "string", desc: "商品标题"),
        Apidoc\Returned("image", type: "string", desc: "商品图片完整URL"),
        Apidoc\Returned("images", type: "array", desc: "商品详情图片列表"),
        Apidoc\Returned("price", type: "float", desc: "价格"),
        Apidoc\Returned("description", type: "string", desc: "商品描述"),
        Apidoc\Returned("artist", type: "string", desc: "艺术家/创作者"),
        Apidoc\Returned("stock", type: "int", desc: "库存数量"),
        Apidoc\Returned("sales", type: "int", desc: "销量"),
        Apidoc\Returned("package_id", type: "int", desc: "资产包ID"),
        Apidoc\Returned("package_name", type: "string", desc: "资产包名称"),
        Apidoc\Returned("object_type", type: "string", desc: "对象类型: item"),
    ]
    public function detail(): void
    {
        $id = $this->request->param('id/d', 0);

        if (!$id) {
            $this->error('参数错误');
        }

        // 查询商品详情
        $detail = Db::name('collection_item')
            ->alias('i')
            ->join('collection_session s', 'i.session_id = s.id', 'LEFT')
            ->where('i.id', $id)
            ->where('i.status', '1')
            ->field([
                'i.id', 'i.title', 'i.image', 'i.images', 'i.price', 'i.description',
                'i.artist', 'i.stock', 'i.sales', 'i.session_id', 'i.zone_id', 
                'i.price_zone', 'i.package_id', 'i.package_name',
                's.title as session_title', 
                's.start_time as session_start_time', 's.end_time as session_end_time'
            ])
            ->find();

        if (!$detail) {
            $this->error('商品不存在或已下架');
        }

        // 处理价格分区
        if (empty($detail['price_zone'])) {
            $detail['price_zone'] = $this->getPriceZone((float)$detail['price']);
        }

        // 处理交易时间状态
        $isTradingTime = false;
        if (!empty($detail['session_id'])) {
             $currentTime = date('H:i');
             $isTradingTime = $this->isTimeInRange($currentTime, $detail['session_start_time'] ?? '', $detail['session_end_time'] ?? '');
        }

        // 格式化数据
        $data = [
            'object_type'        => 'item',
            'id'                 => (int)$detail['id'],
            'title'              => $detail['title'],
            'image'              => $detail['image'] ? toFullUrl($detail['image']) : '',
            'images'             => !empty($detail['images']) ? array_map('toFullUrl', explode(',', $detail['images'])) : [],
            'price'              => (float)$detail['price'], // 商城当前价
            'description'        => $detail['description'],
            'artist'             => $detail['artist'],
            'stock'              => (int)$detail['stock'],
            'sales'              => (int)$detail['sales'],
            // 附加信息
            'session_id'         => (int)$detail['session_id'],
            'zone_id'            => (int)$detail['zone_id'],
            'price_zone'         => $detail['price_zone'],
            'package_id'         => (int)($detail['package_id'] ?? 0),
            'package_name'       => (string)($detail['package_name'] ?? ''),
            'session_title'      => $detail['session_title'] ?? '',
            'session_start_time' => $detail['session_start_time'] ?? '',
            'session_end_time'   => $detail['session_end_time'] ?? '',
            'is_trading_time'    => $isTradingTime,
            // 移除用户私有字段，如买入价、合约信息等
        ];

        $this->success('', $data);
    }

    #[
        Apidoc\Title("官方商品原始详情"),
        Apidoc\Tag("藏品商城,商品详情"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/originalDetail"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "商品ID"),
        Apidoc\Returned("status_text", type: "string", desc: "上架状态文本"),
        Apidoc\Returned("is_consignment", type: "boolean", desc: "是否为寄售产品"),
        Apidoc\Returned("consignment_id", type: "int", desc: "寄售记录ID（仅寄售产品有值）"),
        Apidoc\Returned("consignment_price", type: "float", desc: "寄售价格（仅寄售产品有值）"),
        Apidoc\Returned("consignment_seller_id", type: "int", desc: "寄售卖家ID（仅寄售产品有值）"),
    ]
    public function originalDetail(): void
    {
        $this->error('该接口已废弃');
    }

    /**
     * 生成 0x 开头的 32 字节十六进制指纹
     */
    protected function generateFingerprint(): string
    {
        try {
            $hex = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            $hex = md5(uniqid((string)microtime(true), true));
        }
        return '0x' . $hex;
    }

    /**
     * 脱敏确权编号：将后6位替换为 ******
     * 例如: 37-DATA-0001-000123 → 37-DATA-0001-******
     */
    protected function maskAssetCode(string $assetCode): string
    {
        if (strlen($assetCode) <= 6) {
            return '******';
        }
        return substr($assetCode, 0, -6) . '******';
    }

    #[
        Apidoc\Title("根据专场获取商品列表（含寄售商品）"),
        Apidoc\Tag("藏品商城,商品列表,寄售"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/bySession"),
        Apidoc\Query(name: "session_id", type: "int", require: true, desc: "专场ID"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "10"),
        Apidoc\Returned("list", type: "array", desc: "商品列表（按资产包+分区聚合，包含官方商品和寄售商品）"),
        Apidoc\Returned("list[].id", type: "int", desc: "藏品ID"),
        Apidoc\Returned("list[].session_id", type: "int", desc: "专场ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "商品标题"),
        Apidoc\Returned("list[].image", type: "string", desc: "商品图片URL"),
        Apidoc\Returned("list[].package_name", type: "string", desc: "资产包名称"),
        Apidoc\Returned("list[].package_id", type: "int", desc: "资产包ID"),
        Apidoc\Returned("list[].zone_id", type: "int", desc: "价格分区ID"),
        Apidoc\Returned("list[].price_zone", type: "string", desc: "价格分区名称"),
        Apidoc\Returned("list[].official_stock", type: "int", desc: "官方库存数量"),
        Apidoc\Returned("list[].consignment_count", type: "int", desc: "寄售商品数量"),
        Apidoc\Returned("list[].total_available", type: "int", desc: "总可用数量（官方+寄售）"),
        Apidoc\Returned("list[].sales", type: "int", desc: "已销售数量"),
        Apidoc\Returned("list[].min_price", type: "float", desc: "价格范围最小值"),
        Apidoc\Returned("list[].max_price", type: "float", desc: "价格范围最大值"),
        Apidoc\Returned("list[].price_range", type: "string", desc: "格式化的价格范围（如：350.00-500.00）"),
        Apidoc\Returned("list[].official_min_price", type: "float|null", desc: "官方商品最低价"),
        Apidoc\Returned("list[].consignment_min_price", type: "float|null", desc: "寄售商品最低价"),
        Apidoc\Returned("list[].consignment_list", type: "array", desc: "寄售商品详情列表"),
        Apidoc\Returned("list[].consignment_list[].consignment_id", type: "int", desc: "寄售记录ID"),
        Apidoc\Returned("list[].consignment_list[].price", type: "float", desc: "寄售价格"),
        Apidoc\Returned("list[].consignment_list[].seller_id", type: "int", desc: "卖家用户ID"),
        Apidoc\Returned("list[].consignment_list[].item_id", type: "int", desc: "原藏品ID"),
        Apidoc\Returned("list[].is_consignment", type: "bool", desc: "是否纯寄售商品分组（只有寄售商品，无官方库存）"),
        Apidoc\Returned("list[].session_name", type: "string", desc: "专场名称"),
        Apidoc\Returned("list[].session_start_time", type: "string", desc: "场次开始时间"),
        Apidoc\Returned("list[].session_end_time", type: "string", desc: "场次结束时间"),
        Apidoc\Returned("list[].is_trading_time", type: "bool", desc: "当前是否在交易时间内"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
        Apidoc\Returned("page", type: "int", desc: "当前页码"),
        Apidoc\Returned("limit", type: "int", desc: "每页数量"),
    ]
    public function bySession(): void
    {
        $sessionId = $this->request->param('session_id/d', 0);
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);

        if (!$sessionId) {
            $this->error('专场ID不能为空');
        }

        // 校验专场状态与交易时间
        $session = Db::name('collection_session')
            ->where('id', $sessionId)
            ->where('status', '1')
            ->find();

        if (!$session) {
            $this->error('专场不存在或已下架');
        }

        $currentTime = date('H:i');
        $isInTradingTime = $this->isTimeInRange($currentTime, $session['start_time'] ?? '', $session['end_time'] ?? '');
        if (!$isInTradingTime) {
            $sessionName = $session['title'] ?? '该专场';
            $this->error($sessionName . '交易时间已结束，无法发起预约');
        }

        $limit = min($limit, 50);

        // ============================================================
        // 统一归类逻辑：按 package_name + zone_id 聚合官方商品和寄售商品
        // ============================================================
        
        // 用于存储归类后的数据，key = "package_name|zone_id"
        $groupedData = [];

        // 1) 获取官方商品（上架 status=1，有库存）
        $officialItems = Db::name('collection_item')
            ->where([
                ['status', '=', '1'],
                ['session_id', '=', $sessionId],
                ['stock', '>', 0],
            ])
            ->field([
                'id', 'session_id', 'title', 'image', 'price',
                'package_name', 'package_id', 'zone_id', 'stock', 'sales', 'sort'
            ])
            ->order('sort desc, id desc')
            ->select()
            ->toArray();

        foreach ($officialItems as $item) {
            $packageName = $item['package_name'] ?? '';
            $packageId = (int)($item['package_id'] ?? 0);
            $zoneId = (int)($item['zone_id'] ?? 0);
            $groupKey = $packageName . '|' . $zoneId;

            if (!isset($groupedData[$groupKey])) {
                $groupedData[$groupKey] = [
                    'id' => (int)$item['id'],
                    'session_id' => (int)$item['session_id'],
                    'title' => (string)$item['title'],
                    'image' => $item['image'] ? full_url($item['image'], false) : '',
                    'package_name' => $packageName,
                    'package_id' => $packageId,
                    'zone_id' => $zoneId,
                    'price_zone' => $this->getZoneNameById((int)$zoneId),
                    'official_stock' => 0,
                    'consignment_count' => 0,
                    'total_available' => 0,
                    'min_price' => (float)$item['price'],//最小价格
                    'max_price' => (float)$item['price'],//最大价格
                    'official_min_price' => (float)$item['price'],//官方最小价格
                    'consignment_min_price' => null,//寄售最小价格
                    'consignment_list' => [],
                    'sales' => 0,
                    'session_name' => $session['title'] ?? '',
                    'session_start_time' => $session['start_time'] ?? '',
                    'session_end_time' => $session['end_time'] ?? '',
                    'is_trading_time' => $isInTradingTime,
                ];
            } else {
                // 如果分组已存在，更新 package_id（优先使用非0的值）
                if ($packageId > 0 && ($groupedData[$groupKey]['package_id'] ?? 0) == 0) {
                    $groupedData[$groupKey]['package_id'] = $packageId;
                }
            }

            // 累加官方库存
            $groupedData[$groupKey]['official_stock'] += (int)$item['stock'];
            $groupedData[$groupKey]['total_available'] += (int)$item['stock'];
            $groupedData[$groupKey]['sales'] += (int)$item['sales'];
            
            // 更新价格范围
            $price = (float)$item['price'];
            if ($price < $groupedData[$groupKey]['min_price']) {
                $groupedData[$groupKey]['min_price'] = $price;
            }
            if ($price > $groupedData[$groupKey]['max_price']) {
                $groupedData[$groupKey]['max_price'] = $price;
            }
            if ($price < $groupedData[$groupKey]['official_min_price']) {
                $groupedData[$groupKey]['official_min_price'] = $price;
            }
        }

        // 2) 获取寄售商品（status=1 在售）
        $consignments = Db::name('collection_consignment')
            ->alias('c')
            ->join('collection_item i', 'c.item_id = i.id', 'LEFT')
            ->join('user_collection uc', 'c.user_collection_id = uc.id', 'LEFT') // 关联用户藏品表获取旧资产包标识
            ->where('c.status', 1)
            ->where('i.session_id', $sessionId)
            ->field([
                'c.id AS consignment_id',
                'c.user_id AS seller_id',
                'c.item_id',
                'c.price AS consignment_price',
                'c.package_id AS consignment_package_id', // 寄售单中的package_id
                'c.package_name AS consignment_package_name', // 寄售单中的package_name（旧资产包可能在这里）
                'c.zone_id AS consignment_zone_id', // 寄售单中的zone_id（旧资产包可能在这里）
                'uc.is_old_asset_package', // 添加旧资产包标识
                'i.title',
                'i.image',
                'i.price AS original_price',
                'i.package_name',
                'i.package_id',
                'i.zone_id',
                'i.session_id',
            ])
            ->order('c.price asc, c.id desc')
            ->select()
            ->toArray();

        foreach ($consignments as $c) {
            // 优先使用 collection_item 的字段，如果为空则使用 collection_consignment 的字段（兼容旧资产包）
            $packageName = !empty($c['package_name']) ? $c['package_name'] : ($c['consignment_package_name'] ?? '');
            $packageId = !empty($c['package_id']) ? (int)$c['package_id'] : (int)($c['consignment_package_id'] ?? 0);
            $zoneId = !empty($c['zone_id']) ? (int)$c['zone_id'] : (int)($c['consignment_zone_id'] ?? 0);
            
            // 如果仍然为空，尝试从价格计算分区（兜底逻辑）
            if (empty($packageName) || $zoneId <= 0) {
                $price = (float)($c['consignment_price'] ?? $c['original_price'] ?? 0);
                if ($zoneId <= 0) {
                    // 根据价格查找对应的zone_id
                    $zone = Db::name('price_zone_config')
                        ->where('min_price', '<=', $price)
                        ->where('max_price', '>=', $price)
                        ->where('status', 1)
                        ->find();
                    if ($zone) {
                        $zoneId = (int)$zone['id'];
                    }
                }
                if (empty($packageName)) {
                    $packageName = $this->getPriceZone($price); // 使用价格分区名称作为兜底
                }
            }
            
            $groupKey = $packageName . '|' . $zoneId;

            if (!isset($groupedData[$groupKey])) {
                // 该分组没有官方商品，创建新分组
                $originalPrice = (float)($c['original_price'] ?? $c['consignment_price'] ?? 0);
                $groupedData[$groupKey] = [
                    'id' => (int)$c['item_id'],
                    'session_id' => (int)($c['session_id'] ?? $sessionId),
                    'title' => (string)($c['title'] ?? ''),
                    'image' => $c['image'] ? full_url($c['image'], false) : '',
                    'package_name' => $packageName,
                    'package_id' => $packageId,
                    'zone_id' => $zoneId,
                    'price_zone' => $this->getZoneNameById($zoneId),
                    'official_stock' => 0,
                    'consignment_count' => 0,
                    'total_available' => 0,
                    'min_price' => (float)$c['consignment_price'],
                    'max_price' => (float)$c['consignment_price'],
                    'official_min_price' => null,
                    'consignment_min_price' => (float)$c['consignment_price'],
                    'consignment_list' => [],
                    'sales' => 0,
                    'session_name' => $session['title'] ?? '',
                    'session_start_time' => $session['start_time'] ?? '',
                    'session_end_time' => $session['end_time'] ?? '',
                    'is_trading_time' => $isInTradingTime,
                ];
            } else {
                // 如果分组已存在，更新 package_id（优先使用非0的值）
                if ($packageId > 0 && ($groupedData[$groupKey]['package_id'] ?? 0) == 0) {
                    $groupedData[$groupKey]['package_id'] = $packageId;
                }
            }

            // 累加寄售数量
            $groupedData[$groupKey]['consignment_count'] += 1;
            $groupedData[$groupKey]['total_available'] += 1;

            // 更新价格范围
            $price = (float)$c['consignment_price'];
            if ($price < $groupedData[$groupKey]['min_price']) {
                $groupedData[$groupKey]['min_price'] = $price;
            }
            if ($price > $groupedData[$groupKey]['max_price']) {
                $groupedData[$groupKey]['max_price'] = $price;
            }
            if ($groupedData[$groupKey]['consignment_min_price'] === null || $price < $groupedData[$groupKey]['consignment_min_price']) {
                $groupedData[$groupKey]['consignment_min_price'] = $price;
            }

            // 添加到寄售列表（保留详细信息供前端使用）
            $groupedData[$groupKey]['consignment_list'][] = [
                'consignment_id' => (int)$c['consignment_id'],
                'price' => $price,
                'seller_id' => (int)$c['seller_id'],
                'item_id' => (int)$c['item_id'],
            ];
        }

        // 3) 转换为列表格式并添加兼容字段
        $resultList = [];
        foreach ($groupedData as $group) {
            // 确保所有必需字段都有默认值（兼容旧资产包）
            $group['id'] = (int)($group['id'] ?? 0);
            $group['session_id'] = (int)($group['session_id'] ?? $sessionId);
            $group['title'] = (string)($group['title'] ?? '');
            $group['image'] = (string)($group['image'] ?? '');
            $group['package_name'] = (string)($group['package_name'] ?? '');
            $group['package_id'] = (int)($group['package_id'] ?? 0);
            $group['zone_id'] = (int)($group['zone_id'] ?? 0);
            $group['price_zone'] = str_replace('元区', '', (string)($group['price_zone'] ?? ''));
            $group['official_stock'] = (int)($group['official_stock'] ?? 0);
            $group['consignment_count'] = (int)($group['consignment_count'] ?? 0);
            $group['total_available'] = (int)($group['total_available'] ?? 0);
            $group['min_price'] = (float)($group['min_price'] ?? 0);
            $group['max_price'] = (float)($group['max_price'] ?? 0);
            $group['official_min_price'] = isset($group['official_min_price']) ? (float)$group['official_min_price'] : null;
            $group['consignment_min_price'] = isset($group['consignment_min_price']) ? (float)$group['consignment_min_price'] : null;
            $group['consignment_list'] = is_array($group['consignment_list']) ? $group['consignment_list'] : [];
            $group['sales'] = (int)($group['sales'] ?? 0);
            $group['session_name'] = (string)($group['session_name'] ?? ($session['title'] ?? ''));
            $group['session_start_time'] = (string)($group['session_start_time'] ?? ($session['start_time'] ?? ''));
            $group['session_end_time'] = (string)($group['session_end_time'] ?? ($session['end_time'] ?? ''));
            $group['is_trading_time'] = (bool)($group['is_trading_time'] ?? $isInTradingTime);

            // 设置分组标识
            $group['is_consignment'] = $group['consignment_count'] > 0 && $group['official_stock'] === 0;

            // 价格范围显示
            if ($group['min_price'] == $group['max_price']) {
                $group['price_range'] = sprintf('%.2f', $group['min_price']);
            } else {
                $group['price_range'] = sprintf('%.2f - %.2f', $group['min_price'], $group['max_price']);
            }

            $resultList[] = $group;
        }

        // 4) 分页
        $total = count($resultList);
        $offset = max(0, ($page - 1) * $limit);
        $pagedList = array_slice($resultList, $offset, $limit);

        $this->success('', [
            'list' => array_values($pagedList),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 盲盒预约接口（冻结专项资金与算力）
     * 仅支持盲盒撮合模式，预约价格分区而非具体商品
     * 必填参数：session_id（场次ID）、zone_id（价格分区ID）、package_id（资产包ID）
     * 可选参数：extra_hashrate（额外加注算力，用于增加权重）
     * @throws \Exception
     */
    #[
    Apidoc\Title("盲盒预约（冻结专项资金与算力）"),
    Apidoc\Tag("藏品商城,盲盒,撮合池,预约"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/collectionItem/bidBuy"),
    Apidoc\Query(name: "session_id", type: "int", require: true, desc: "场次ID（必填）"),
    Apidoc\Query(name: "zone_id", type: "int", require: true, desc: "价格分区ID（必填，如1=500元区）"),
    Apidoc\Query(name: "package_id", type: "int", require: true, desc: "资产包ID（必填）"),
        Apidoc\Query(name: "extra_hashrate", type: "float", require: false, desc: "额外加注算力（用于增加权重）", default: 0),
        Apidoc\Returned("reservation_id", type: "int", desc: "预约记录ID"),
        Apidoc\Returned("freeze_amount", type: "float", desc: "冻结金额（分区最高价）"),
        Apidoc\Returned("power_used", type: "float", desc: "消耗的算力"),
        Apidoc\Returned("weight", type: "int", desc: "获得的权重"),
        Apidoc\Returned("zone_name", type: "string", desc: "分区名称"),
        Apidoc\Returned("package_id", type: "int", desc: "资产包ID"),
        Apidoc\Returned("package_name", type: "string", desc: "资产包名称"),
        Apidoc\Returned("message", type: "string", desc: "提示信息"),
    ]
    public function bidBuy(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        // 盲盒预约模式：必填 session_id + zone_id + package_id，可选 extra_hashrate
        $sessionId = $this->request->param('session_id/d', 0);
        $zoneId = $this->request->param('zone_id/d', 0);
        $packageId = $this->request->param('package_id/d', 0);
        $extraHashrate = (float)$this->request->param('extra_hashrate/f', 0.0);

        $userId = $this->auth->id;

        // 参数验证
        if ($zoneId <= 0 || $sessionId <= 0) {
            $this->error('请选择场次(session_id)和价格分区(zone_id)进行预约');
        }
        
        if ($packageId <= 0) {
            $this->error('请选择资产包(package_id)进行预约');
        }

        // 读取价格分区配置
        $zone = Db::name('price_zone_config')
            ->where('id', $zoneId)
            ->where('status', '1')
            ->find();
        if (!$zone) {
            $this->error('价格分区不存在或未启用');
        }
        
        // 读取场次信息
        $session = Db::name('collection_session')
            ->where('id', $sessionId)
            ->where('status', '1')
            ->find();
        if (!$session) {
            $this->error('交易场次不存在或未开启');
        }
        
        // 验证资产包ID是否有效
        $package = Db::name('asset_package')
            ->where('id', $packageId)
            ->where('status', 1)
            ->find();
        
        if (!$package) {
            $this->error('指定的资产包不存在或未启用');
        }
        
        // 验证资产包是否属于该场次和分区
        // 注意：所有资产包都是通用包（zone_id=0），可以用于所有价格分区
        // 通用包（zone_id=0）的验证总是通过，此逻辑保留以兼容将来可能的特定分区包
        if ($package['session_id'] != $sessionId) {
            $this->error('资产包不属于指定的场次');
        }
        
        // 通用包（zone_id=0）可以用于所有价格分区，此验证对通用包总是通过
        if ($package['zone_id'] != 0 && $package['zone_id'] != $zoneId) {
            $this->error('资产包不属于指定的价格分区');
        }
        
        // 读取配置（可在后台配置）
        $baseCost = (float)(get_sys_config('rush_base_cost') ?? 5);
        $maxBoost = (int)(get_sys_config('rush_boost_max') ?? 50);
        $boostRatio = (int)(get_sys_config('rush_boost_ratio') ?? 10);

        if ($extraHashrate < 0 || $extraHashrate > $maxBoost) {
            $this->error("加注范围：0-{$maxBoost}点");
        }

        $totalHashrate = $baseCost + $extraHashrate;
        $finalWeight = (int)(100 + ($extraHashrate * $boostRatio));
        
        // 冻结金额 = 分区最高价
        $freezeAmount = (float)$zone['max_price'];
        if ($freezeAmount <= 0) {
            // 如果max_price为空或0（如开放区），使用min_price + 500
            $freezeAmount = (float)$zone['min_price'] + 500;
        }
        
        $now = time();

        Db::startTrans();
        try {
            // 锁定用户
            $user = Db::name('user')->where('id', $userId)->lock(true)->find();
            if (!$user) {
                throw new \Exception('用户不存在');
            }

            // 检查绿色算力
            $userGreenPower = (float)($user['green_power'] ?? 0);
            if ($userGreenPower < $totalHashrate) {
                throw new \Exception('绿色算力不足，请先兑换');
            }

            // 供应链专项金使用用户可用余额（专项金）
            $userAvailable = (float)($user['balance_available'] ?? 0);
            if ($userAvailable < $freezeAmount) {
                throw new \Exception('供应链专项金不足，需要' . $freezeAmount . '元');
            }

            // 扣除算力（直接销毁）
            Db::name('user')->where('id', $userId)->dec('green_power', $totalHashrate)->update(['update_time' => $now]);

            // 扣除专项资金（只扣除 balance_available，money 是派生值会自动计算）
            $beforeBalance = (float)($user['balance_available'] ?? 0);
            $afterBalance = round($beforeBalance - $freezeAmount, 2);
            
            Db::name('user')->where('id', $userId)->update([
                'balance_available' => $afterBalance,
                'update_time' => $now,
            ]);
            
            // 插入预约记录（盲盒模式：zone_id有值，product_id=0）
            $reservationId = Db::name('trade_reservations')->insertGetId([
                'user_id' => $userId,
                'session_id' => $sessionId,
                'zone_id' => $zoneId,
                'package_id' => $packageId,  // 资产包ID（用于撮合时匹配）
                'product_id' => 0,  // 盲盒模式，预约时不知道具体商品
                'freeze_amount' => $freezeAmount,
                'power_used' => $totalHashrate,
                'base_hashrate_cost' => $baseCost,
                'extra_hashrate_cost' => $extraHashrate,
                'weight' => $finalWeight,
                'status' => 0,  // 待撮合
                'match_order_id' => 0,
                'match_time' => null,
                'create_time' => $now,
                'update_time' => $now,
            ]);

            // 记录可用余额变动（在创建预约记录后，可以关联reservation_id）
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
                'before' => $beforeBalance,
                'after' => $afterBalance,
                'memo' => '盲盒预约冻结可用余额 - ' . $zone['name'],
                'create_time' => $now,
            ]);

            // 🆕 记录算力扣除流水
            Db::name('user_money_log')->insert([
                'user_id' => $userId,
                'flow_no' => generateSJSFlowNo($userId), // 生成新的流水号
                'batch_no' => $batchNo, // 使用相同的批次号
                'biz_type' => 'blind_box_reserve',
                'biz_id' => $reservationId,
                'field_type' => 'green_power',
                'money' => -$totalHashrate,
                'before' => $userGreenPower,
                'after' => $userGreenPower - $totalHashrate,
                'memo' => '盲盒预约消耗绿色算力 - ' . $zone['name'],
                'create_time' => $now,
            ]);

            // 记录活动日志（算力与冻结）
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'action_type' => 'blind_box_reserve',
                'change_field' => 'green_power,freeze_amount',
                'change_value' => json_encode(['green_power' => -$totalHashrate, 'freeze_amount' => -$freezeAmount], JSON_UNESCAPED_UNICODE),
                'before_value' => json_encode(['green_power' => $userGreenPower, 'available_money' => $userAvailable], JSON_UNESCAPED_UNICODE),
                'after_value' => json_encode(['green_power' => $userGreenPower - $totalHashrate, 'available_money' => $userAvailable - $freezeAmount], JSON_UNESCAPED_UNICODE),
                'remark' => sprintf('盲盒预约 %s 场次#%d，算力消耗%.2f，冻结金额%.2f', $zone['name'], $sessionId, $totalHashrate, $freezeAmount),
                'extra' => json_encode(['session_id' => $sessionId, 'zone_id' => $zoneId, 'zone_name' => $zone['name'], 'reservation_id' => $reservationId], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
            ]);

            Db::commit();
            $this->success('盲盒预约成功！等待撮合结果', [
                'reservation_id' => $reservationId,
                'freeze_amount' => $freezeAmount,
                'power_used' => $totalHashrate,
                'weight' => $finalWeight,
                'zone_id' => $zoneId,
                'zone_name' => $zone['name'],
                'session_id' => $sessionId,
                'package_id' => $packageId,
                'package_name' => $package['name'] ?? '',
                'message' => '预约并冻结成功，等待撮合。中签后将匹配' . $zone['name'] . '内商品。',
            ]);
        } catch (HttpResponseException $e) {
            Db::rollback();
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    // 已移除单独的 `reserve()` 方法；预约逻辑已合并到 `bidBuy()`，请使用传入 `session_id` + `zone_id` + `package_id` + `extra_hashrate` 调用预约并冻结专项资金。

    #[
        Apidoc\Title("手动上架"),
        Apidoc\Tag("藏品商城,持有"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/collectionItem/setAutoRelist"),
        Apidoc\Query(name: "collection_id", type: "int", require: true, desc: "用户藏品记录ID"),
        Apidoc\Returned("message", type: "string", desc: "提示信息"),
    ]
    public function setAutoRelist(): void
    {
        $this->error('该接口已废弃');
    }

    #[
        Apidoc\Title("查询预约记录列表"),
        Apidoc\Tag("藏品商城,盲盒预约"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/reservations"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Query(name: "status", type: "int", require: false, desc: "状态筛选：0=待撮合,1=已撮合,2=已退款,-1=全部", default: "-1"),
        Apidoc\Returned("list", type: "array", desc: "预约记录列表"),
        Apidoc\Returned("total", type: "int", desc: "总数"),
    ]
    public function reservations(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;
        $page = max(1, (int)$this->request->param('page/d', 1));
        $limit = min(50, max(1, (int)$this->request->param('limit/d', 10)));
        $status = (int)$this->request->param('status/d', -1);

        $query = Db::name('trade_reservations')
            ->alias('r')
            ->leftJoin('collection_session s', 'r.session_id = s.id')
            ->leftJoin('price_zone_config z', 'r.zone_id = z.id')
            ->leftJoin('collection_item i', 'r.product_id = i.id')
            ->where('r.user_id', $userId);

        // 状态筛选
        if ($status >= 0) {
            $query->where('r.status', $status);
        }

        // 获取总数
        $total = (clone $query)->count();

        // 获取列表
        $list = $query
            ->field([
                'r.id',
                'r.session_id',
                'r.zone_id',
                'r.product_id',
                'r.freeze_amount',
                'r.power_used',
                'r.base_hashrate_cost',
                'r.extra_hashrate_cost',
                'r.weight',
                'r.status',
                'r.match_order_id',
                'r.match_time',
                'r.create_time',
                'r.update_time',
                's.title as session_title',
                's.start_time as session_start_time',
                's.end_time as session_end_time',
                'z.name as zone_name',
                'z.min_price as zone_min_price',
                'z.max_price as zone_max_price',
                'i.title as item_title',
                'i.image as item_image',
                'i.price as item_price',  // 当前价格（已增值）
            ])
            ->order('r.id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 批量获取实际购买价格（从 user_collection）
        $reservationIds = array_column($list, 'id');
        $actualPrices = [];
        if (!empty($reservationIds)) {
            // 通过 match_order_id 关联到 collection_order，再关联到 user_collection
            $userCollections = Db::name('user_collection')
                ->alias('uc')
                ->leftJoin('collection_order co', 'uc.order_id = co.id')
                ->leftJoin('trade_reservations r', 'co.id = r.match_order_id')
                ->where('r.id', 'in', $reservationIds)
                ->field(['r.id as reservation_id', 'uc.price as actual_price'])
                ->select()
                ->toArray();
            
            foreach ($userCollections as $uc) {
                $actualPrices[$uc['reservation_id']] = (float)$uc['actual_price'];
            }
        }

        // 格式化输出
        $statusMap = [
            0 => '待撮合',
            1 => '已撮合',
            2 => '已退款',
            3 => '已取消',  // 🔧 修复：添加缺失的状态3
        ];

        foreach ($list as &$row) {
            $row['id'] = (int)$row['id'];
            $row['session_id'] = (int)$row['session_id'];
            $row['zone_id'] = (int)$row['zone_id'];
            $row['product_id'] = (int)$row['product_id'];
            $row['freeze_amount'] = (float)$row['freeze_amount'];
            $row['power_used'] = (float)$row['power_used'];
            $row['base_hashrate_cost'] = (float)$row['base_hashrate_cost'];
            $row['extra_hashrate_cost'] = (float)$row['extra_hashrate_cost'];
            $row['weight'] = (int)$row['weight'];
            $row['status'] = (int)$row['status'];
            $row['status_text'] = $statusMap[$row['status']] ?? '未知';
            $row['match_order_id'] = (int)$row['match_order_id'];
            $row['match_time'] = $row['match_time'] ? date('Y-m-d H:i:s', $row['match_time']) : null;
            $row['create_time'] = $row['create_time'] ? date('Y-m-d H:i:s', $row['create_time']) : null;
            $row['update_time'] = $row['update_time'] ? date('Y-m-d H:i:s', $row['update_time']) : null;
            $row['session_title'] = $row['session_title'] ?? '';
            $row['session_start_time'] = $row['session_start_time'] ?? '';
            $row['session_end_time'] = $row['session_end_time'] ?? '';
            // 🔧 修复：如果冻结金额与当前分区最高价不匹配，根据冻结金额反推正确的分区
            $freezeAmount = (float)($row['freeze_amount'] ?? 0);
            $currentZoneMaxPrice = (float)($row['zone_max_price'] ?? 0);
            
            // 如果冻结金额与当前分区最高价不匹配，尝试根据冻结金额匹配正确的分区
            if ($freezeAmount > 0 && abs($freezeAmount - $currentZoneMaxPrice) > 0.01) {
                $correctZone = Db::name('price_zone_config')
                    ->where('status', 1)
                    ->where('max_price', $freezeAmount)
                    ->find();
                
                if ($correctZone) {
                    // 使用根据冻结金额匹配到的正确分区
                    $row['zone_name'] = $correctZone['name'];
                    $row['zone_min_price'] = (float)$correctZone['min_price'];
                    $row['zone_max_price'] = (float)$correctZone['max_price'];
                } else {
                    // 如果找不到完全匹配的，使用当前关联的分区（保持原逻辑）
                    $row['zone_name'] = $row['zone_name'] ?? '';
                    $row['zone_min_price'] = (float)($row['zone_min_price'] ?? 0);
                    $row['zone_max_price'] = (float)($row['zone_max_price'] ?? 0);
                }
            } else {
                // 冻结金额与分区最高价匹配，使用当前关联的分区
                $row['zone_name'] = $row['zone_name'] ?? '';
                $row['zone_min_price'] = (float)($row['zone_min_price'] ?? 0);
                $row['zone_max_price'] = (float)($row['zone_max_price'] ?? 0);
            }
            
            $row['item_title'] = $row['item_title'] ?? '';
            $row['item_image'] = $row['item_image'] ? full_url($row['item_image'], false) : '';
            $row['item_price'] = (float)($row['item_price'] ?? 0);  // 当前价格（已增值）
            
            // 添加实际购买价格（如果已撮合）
            $reservationId = (int)$row['id'];
            $row['actual_buy_price'] = $actualPrices[$reservationId] ?? 0;  // 实际购买价格
            
            // 计算退款差价（冻结金额 - 实际购买价格）
            if ($row['actual_buy_price'] > 0) {
                $row['refund_diff'] = round($row['freeze_amount'] - $row['actual_buy_price'], 2);
            } else {
                $row['refund_diff'] = 0;
            }
        }
        unset($row);

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }
    #[
        Apidoc\Title("预约记录详情"),
        Apidoc\Tag("藏品商城,盲盒预约"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/reservationDetail"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "预约记录ID"),
        Apidoc\Returned("id", type: "int", desc: "预约记录ID"),
        Apidoc\Returned("status", type: "int", desc: "状态(0=待撮合,1=已撮合,2=已退款,3=已取消)"),
        Apidoc\Returned("status_text", type: "string", desc: "状态文本"),
        Apidoc\Returned("freeze_amount", type: "float", desc: "冻结金额"),
        Apidoc\Returned("power_used", type: "float", desc: "使用的总算力"),
        Apidoc\Returned("base_hashrate_cost", type: "float", desc: "基础算力成本"),
        Apidoc\Returned("extra_hashrate_cost", type: "float", desc: "额外加注算力"),
        Apidoc\Returned("weight", type: "int", desc: "获得的权重"),
        Apidoc\Returned("session_id", type: "int", desc: "场次ID"),
        Apidoc\Returned("session_title", type: "string", desc: "场次名称"),
        Apidoc\Returned("session_start_time", type: "string", desc: "场次开始时间"),
        Apidoc\Returned("session_end_time", type: "string", desc: "场次结束时间"),
        Apidoc\Returned("zone_id", type: "int", desc: "价格分区ID"),
        Apidoc\Returned("zone_name", type: "string", desc: "分区名称"),
        Apidoc\Returned("zone_min_price", type: "float", desc: "分区最低价"),
        Apidoc\Returned("zone_max_price", type: "float", desc: "分区最高价"),
        Apidoc\Returned("match_order_id", type: "int", desc: "撮合订单ID(已撮合时)"),
        Apidoc\Returned("match_time", type: "string", desc: "撮合时间(已撮合时)"),
        Apidoc\Returned("product_id", type: "int", desc: "获得的商品ID(已撮合时)"),
        Apidoc\Returned("item_title", type: "string", desc: "商品标题(已撮合时)"),
        Apidoc\Returned("item_image", type: "string", desc: "商品图片(已撮合时)"),
        Apidoc\Returned("actual_buy_price", type: "float", desc: "实际购买价格(已撮合时)"),
        Apidoc\Returned("refund_diff", type: "float", desc: "退款差价(已撮合时)"),
        Apidoc\Returned("create_time", type: "string", desc: "创建时间"),
        Apidoc\Returned("update_time", type: "string", desc: "更新时间"),
    ]
    public function reservationDetail(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $id = (int)$this->request->param('id/d', 0);
        if (!$id) {
            $this->error('参数错误');
        }

        $userId = $this->auth->id;

        // 查询预约记录
        $reservation = Db::name('trade_reservations')
            ->alias('r')
            ->leftJoin('collection_session s', 'r.session_id = s.id')
            ->leftJoin('price_zone_config z', 'r.zone_id = z.id')
            ->leftJoin('collection_item i', 'r.product_id = i.id')
            ->where('r.id', $id)
            ->where('r.user_id', $userId)
            ->field([
                'r.id',
                'r.user_id',
                'r.session_id',
                'r.zone_id',
                'r.package_id',
                'r.product_id',
                'r.freeze_amount',
                'r.power_used',
                'r.base_hashrate_cost',
                'r.extra_hashrate_cost',
                'r.weight',
                'r.status',
                'r.match_order_id',
                'r.match_time',
                'r.create_time',
                'r.update_time',
                's.title as session_title',
                's.start_time as session_start_time',
                's.end_time as session_end_time',
                'z.name as zone_name',
                'z.min_price as zone_min_price',
                'z.max_price as zone_max_price',
                'i.title as item_title',
                'i.image as item_image',
                'i.price as item_price',
            ])
            ->find();

        if (!$reservation) {
            $this->error('预约记录不存在或无权限查看');
        }

        // 获取实际购买价格（如果已撮合）
        $actualBuyPrice = 0;
        if ($reservation['status'] == 1 && $reservation['match_order_id'] > 0) {
            $userCollection = Db::name('user_collection')
                ->alias('uc')
                ->leftJoin('collection_order co', 'uc.order_id = co.id')
                ->where('co.id', $reservation['match_order_id'])
                ->field('uc.price')
                ->find();
            
            if ($userCollection) {
                $actualBuyPrice = (float)$userCollection['price'];
            }
        }

        // 状态映射
        $statusMap = [
            0 => '待撮合',
            1 => '已撮合',
            2 => '已退款',
            3 => '已取消',
        ];

        // 格式化输出
        $data = [
            'id' => (int)$reservation['id'],
            'status' => (int)$reservation['status'],
            'status_text' => $statusMap[$reservation['status']] ?? '未知',
            'freeze_amount' => (float)$reservation['freeze_amount'],
            'power_used' => (float)$reservation['power_used'],
            'base_hashrate_cost' => (float)$reservation['base_hashrate_cost'],
            'extra_hashrate_cost' => (float)$reservation['extra_hashrate_cost'],
            'weight' => (int)$reservation['weight'],
            'session_id' => (int)$reservation['session_id'],
            'session_title' => $reservation['session_title'] ?? '',
            'session_start_time' => $reservation['session_start_time'] ?? '',
            'session_end_time' => $reservation['session_end_time'] ?? '',
            'zone_id' => (int)$reservation['zone_id'],
            'zone_name' => $reservation['zone_name'] ?? '',
            'zone_min_price' => (float)($reservation['zone_min_price'] ?? 0),
            'zone_max_price' => (float)($reservation['zone_max_price'] ?? 0),
            'package_id' => (int)$reservation['package_id'],
            'match_order_id' => (int)$reservation['match_order_id'],
            'match_time' => $reservation['match_time'] ? date('Y-m-d H:i:s', $reservation['match_time']) : null,
            'product_id' => (int)$reservation['product_id'],
            'item_title' => $reservation['item_title'] ?? '',
            'item_image' => $reservation['item_image'] ? toFullUrl($reservation['item_image']) : '',
            'item_price' => (float)($reservation['item_price'] ?? 0),
            'actual_buy_price' => $actualBuyPrice,
            'refund_diff' => $actualBuyPrice > 0 ? round($reservation['freeze_amount'] - $actualBuyPrice, 2) : 0,
            'create_time' => $reservation['create_time'] ? date('Y-m-d H:i:s', $reservation['create_time']) : null,
            'update_time' => $reservation['update_time'] ? date('Y-m-d H:i:s', $reservation['update_time']) : null,
        ];

        $this->success('', $data);
    }

    // ============================================================
    // [2025-12-26] matchingPool 接口已废弃
    // 原因：改为盲盒预约模式
    // 替代接口：GET /api/collectionItem/reservations
    // ============================================================
    #[
        Apidoc\Title("查询撮合池列表（已废弃）"),
        Apidoc\Tag("藏品商城,已废弃"),
        Apidoc\Method("GET"),
    ]
    public function matchingPool(): void
    {
        $this->error('此接口已废弃，请使用 GET /api/collectionItem/reservations 查询预约记录', [], 410);
    }

    // 已移除接口：取消竞价（从撮合池移除）。如需恢复，请在版本历史中查看删除记录或联系开发者。

    /**
     * 获取撮合状态文本
     */
    private function getMatchingStatusText(string $status): string
    {
        $statusMap = [
            'pending' => '待撮合',
            'matched' => '已撮合',
            'cancelled' => '已取消',
        ];
        return $statusMap[$status] ?? '未知';
    }

    protected function processConsignmentPurchase(int $consignmentId, string $payType): array
    {
        if ($payType !== 'money') {
            throw new \Exception('当前寄售只支持余额支付');
        }

        $buyerId = $this->auth->id;

        Db::startTrans();
        try {
            // 1. 查询寄售记录并锁定
            $consignment = Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->lock(true)
                ->find();

            if (!$consignment) {
                throw new \Exception('寄售记录不存在');
            }

            if ((int)$consignment['status'] !== 1) {
                throw new \Exception('该寄售记录当前状态不可购买');
            }

            $sellerId = (int)$consignment['user_id'];
            $userCollectionId = (int)$consignment['user_collection_id'];
            $itemId = (int)$consignment['item_id'];
            $consignmentPrice = (float)$consignment['price'];

            if ($sellerId === $buyerId) {
                throw new \Exception('不能购买自己寄售的藏品');
            }

            if ($consignmentPrice <= 0) {
                throw new \Exception('寄售价格异常');
            }

            // 2. 查询藏品信息（用于展示）
            // 寄售购买场景下，原始商品可能已在商城下架（status=0），
            // 这里只用于展示标题和图片，不再强制要求 status=1
            $item = Db::name('collection_item')
                ->where('id', $itemId)
                ->find();

            if (!$item) {
                throw new \Exception('藏品不存在或已下架');
            }

            // 3. 查询买家、卖家并锁定
            $buyer = Db::name('user')
                ->where('id', $buyerId)
                ->lock(true)
                ->find();
            if (!$buyer) {
                throw new \Exception('买家不存在');
            }

            $seller = Db::name('user')
                ->where('id', $sellerId)
                ->lock(true)
                ->find();
            if (!$seller) {
                throw new \Exception('卖家不存在');
            }

            // 4. 检查买家余额（混合支付：优先可用余额，不足时用可提现余额）
            $buyerBalanceAvailable = (float)$buyer['balance_available'];
            $buyerWithdrawableMoney = (float)$buyer['withdrawable_money'];
            $totalAvailable = $buyerBalanceAvailable + $buyerWithdrawableMoney;
            
            if ($totalAvailable < $consignmentPrice) {
                throw new \Exception('余额不足，当前可用：' . number_format($totalAvailable, 2) . '元');
            }

            $now = time();

            // 5. 扣减买家余额（混合支付逻辑：优先扣可用余额，不足时扣可提现余额）
            $payFromBalance = min($buyerBalanceAvailable, $consignmentPrice);
            $payFromWithdrawable = $consignmentPrice - $payFromBalance;
            
            $buyerAfterBalance = $buyerBalanceAvailable - $payFromBalance;
            $buyerAfterWithdrawable = $buyerWithdrawableMoney - $payFromWithdrawable;
            
            Db::name('user')
                ->where('id', $buyerId)
                ->update([
                    'balance_available' => $buyerAfterBalance,
                    'withdrawable_money' => $buyerAfterWithdrawable,
                    'update_time' => $now,
                ]);

            // 记录余额变动日志（如果扣除了可用余额）
            // 生成流水号和批次号
            $flowNo1 = generateSJSFlowNo($buyerId);
            $flowNo2 = generateSJSFlowNo($buyerId);
            while ($flowNo2 === $flowNo1) {
                $flowNo2 = generateSJSFlowNo($buyerId);
            }
            $batchNo = generateBatchNo('CONSIGN_BUY', $consignmentId);
            
            if ($payFromBalance > 0) {
                Db::name('user_money_log')->insert([
                    'user_id' => $buyerId,
                    'flow_no' => $flowNo1,
                    'batch_no' => $batchNo,
                    'biz_type' => 'consign_buy',
                    'biz_id' => $consignmentId,
                    'field_type' => 'balance_available',
                    'money' => -$payFromBalance,
                    'before' => $buyerBalanceAvailable,
                    'after' => $buyerAfterBalance,
                    'memo' => '购买寄售藏品（可用余额）：' . $item['title'],
                    'create_time' => $now,
                ]);
            }
            
            // 记录可提现余额变动日志（如果扣除了可提现余额）
            if ($payFromWithdrawable > 0) {
                Db::name('user_money_log')->insert([
                    'user_id' => $buyerId,
                    'flow_no' => $flowNo2,
                    'batch_no' => $batchNo,
                    'biz_type' => 'consign_buy',
                    'biz_id' => $consignmentId,
                    'field_type' => 'withdrawable_money',
                    'money' => -$payFromWithdrawable,
                    'before' => $buyerWithdrawableMoney,
                    'after' => $buyerAfterWithdrawable,
                    'memo' => '购买寄售藏品（可提现余额）：' . $item['title'],
                    'create_time' => $now,
                ]);
            }

            // 6. 交易结算：使用差价模式计算本金和利润（与盲盒撮合逻辑统一）
            // 利润 = 寄售价 - 卖家原购买价格（差价）
            
            // 获取卖家的原始购买价格（从user_collection表）
            $sellerCollection = Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->find();
            
            $originalBuyPrice = $sellerCollection ? (float)$sellerCollection['price'] : 0;
            if ($originalBuyPrice <= 0) {
                $originalBuyPrice = $consignmentPrice; // 兼容处理：找不到买入价则使用寄售价作为本金
            }
            
            // 判断是否是旧资产包（旧资产包不退手续费）
            $isOldAssetPackage = $sellerCollection && (int)($sellerCollection['is_old_asset_package'] ?? 0) === 1;
            
            // 计算差价利润 = 寄售价 - 原购买价
            $profit = max(0, round($consignmentPrice - $originalBuyPrice, 2));
            
            // 手续费退还逻辑（旧资产包不退手续费）
            $serviceFeeRate = (float)(get_sys_config('consignment_service_fee_rate') ?? 0.03);
            $feeRefund = $isOldAssetPackage ? 0 : round($originalBuyPrice * $serviceFeeRate, 2);
            
            // 剩余利润 = 差价利润 - 手续费退还
            $remainingProfit = max(0, $profit - $feeRefund);
            
            // 从配置读取利润分配比例（默认50%进可提现余额，50%进消费金）
            $splitRate = (float)(get_sys_config('seller_profit_split_rate') ?? 0.5);
            if ($splitRate < 0 || $splitRate > 1) {
                $splitRate = 0.5;
            }
            
            // 计算利润分配
            $profitToBalance = round($remainingProfit * $splitRate, 2); // 利润进可提现余额的部分
            $profitToScore = (int)round($remainingProfit * (1 - $splitRate)); // 利润进消费金的部分（整数）
            
            // 卖家最终可提现收益 = 本金（原购买价格） + 手续费退还 + 利润可提现部分
            $sellerTotalWithdrawable = $originalBuyPrice + $feeRefund + $profitToBalance;
            
            // 更新卖家余额（本金+利润余额部分进withdrawable_money，利润积分部分进score）
            $sellerBeforeWithdrawable = (float)$seller['withdrawable_money'];
            $sellerBeforeScore = (float)$seller['score'];
            $sellerAfterWithdrawable = $sellerBeforeWithdrawable + $sellerTotalWithdrawable;
            $sellerAfterScore = $sellerBeforeScore + $profitToScore;
            
            Db::name('user')
                ->where('id', $sellerId)
                ->update([
                    'withdrawable_money' => $sellerAfterWithdrawable,
                    'score' => $sellerAfterScore,
                    'update_time' => $now,
                ]);

            // 生成批次号（同一笔寄售成交）
            $settleBatchNo = generateBatchNo('CONSIGN', $consignmentId);
            
            // 流水 1: 提现余额入账（交易成功）
            Db::name('user_money_log')->insert([
                'flow_no' => generateFlowNo(),
                'batch_no' => $settleBatchNo,
                'biz_type' => 'consign_settle',
                'biz_id' => $consignmentId,
                'user_collection_id' => $userCollectionId,
                'item_id' => $itemId,
                'title_snapshot' => $item['title'],
                'image_snapshot' => $item['image'] ?? '',
                'user_id' => $sellerId,
                'field_type' => 'withdrawable_money',
                'money' => $sellerTotalWithdrawable,
                'before' => $sellerBeforeWithdrawable,
                'after' => $sellerAfterWithdrawable,
                'memo' => '交易成功：' . $item['title'],
                'extra_json' => json_encode([
                    'sale_price' => $consignmentPrice,
                    'buy_price' => $originalBuyPrice,
                    'principal' => $originalBuyPrice,
                    'profit' => $profit,
                    'fee_refund' => $feeRefund,
                    'remaining_profit' => $remainingProfit,
                    'profit_to_balance' => $profitToBalance,
                    'profit_to_score' => $profitToScore,
                    'payout_withdrawable' => $sellerTotalWithdrawable,
                    'is_old_asset_package' => $isOldAssetPackage ? 1 : 0,
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
            ]);
            
            // 流水 2: 消费金入账（如果有积分变动）
            if ($profitToScore > 0) {
                Db::name('user_score_log')->insert([
                    'flow_no' => generateFlowNo(),
                    'batch_no' => $settleBatchNo,
                    'biz_type' => 'consign_settle_score',
                    'biz_id' => $consignmentId,
                    'user_collection_id' => $userCollectionId,
                    'item_id' => $itemId,
                    'title_snapshot' => $item['title'],
                    'image_snapshot' => $item['image'] ?? '',
                    'user_id' => $sellerId,
                    'score' => $profitToScore,
                    'before' => $sellerBeforeScore,
                    'after' => $sellerAfterScore,
                    'memo' => '交易成功（消费金）：' . $item['title'],
                    'extra_json' => json_encode([
                        'profit_to_score' => $profitToScore,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                ]);
            }

            // 7. 创建订单（买家侧）
            $orderNo = 'CC' . date('YmdHis') . str_pad($buyerId, 6, '0', STR_PAD_LEFT) . rand(1000, 9999);

            $orderData = [
                'order_no' => $orderNo,
                'user_id' => $buyerId,
                'total_amount' => $consignmentPrice,
                'pay_type' => $payType,
                'status' => 'paid',
                'remark' => '寄售购买|consignment_id:' . $consignmentId . '|seller_id:' . $sellerId,
                'pay_time' => $now,
                'complete_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
            ];

            $orderId = Db::name('collection_order')->insertGetId($orderData);
            if (!$orderId) {
                throw new \Exception('创建寄售订单失败');
            }

            Db::name('collection_order_item')->insert([
                'order_id' => $orderId,
                'item_id' => $itemId,
                'item_title' => $item['title'],
                'item_image' => $item['image'],
                'price' => $consignmentPrice,
                'quantity' => 1,
                'subtotal' => $consignmentPrice,
                'product_id_record' => '寄售购买',
                'create_time' => $now,
            ]);

            // 8. 更新寄售记录与卖家持有记录
            Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->update([
                    'status' => 2, // 已售出
                    'update_time' => $now,
                ]);

            Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->where('user_id', $sellerId)
                ->update([
                    'consignment_status' => 2, // 已售出
                    'update_time' => $now,
                ]);

            // 9. 给买家生成新的持有记录
            Db::name('user_collection')->insert([
                'user_id' => $buyerId,
                'order_id' => $orderId,
                'order_item_id' => 0,
                'item_id' => $itemId,
                'title' => $item['title'],
                'image' => $item['image'],
                'price' => $consignmentPrice,
                'buy_time' => $now,
                'delivery_status' => 0,
                'consignment_status' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);

            // 10. 检查并升级买家用户等级，交易用户发放场次+区间绑定寄售券
            $itemSessionId = (int)($item['session_id'] ?? 0);
            $itemZoneId = (int)($item['zone_id'] ?? 0);
            
            // 🆕 检查是否是"旧资产"（权益证或特定描述）
            $isOldAsset = false;
            if (strpos($item['title'], '权益证') !== false || strpos($item['description'] ?? '', '旧资产') !== false) {
                 $isOldAsset = true;
            }
            
            // 如果是旧资产，强制发放寄售券（即使是第一次购买）
            UserService::checkAndUpgradeUserAfterPurchase($buyerId, $itemSessionId, $itemZoneId, $isOldAsset);

            // 11. 商品被买走后，自动上涨价格（4%-6%随机）
            $this->updateItemPriceAfterPurchase($itemId, (float)$item['price']);

            // 12. 下架该藏品：一旦寄售被买走，商城和寄售区都不再展示
            Db::name('collection_item')
                ->where('id', $itemId)
                ->update([
                    'status' => '0',
                    'stock' => 0,
                    'update_time' => $now,
                ]);

            // 12. 记录用户活动日志（买家支出）
            // 买家支出：混合支付，根据实际扣款情况记录
            if ($payFromBalance > 0) {
                Db::name('user_activity_log')->insert([
                    'user_id' => $buyerId,
                    'related_user_id' => $sellerId,
                    'action_type' => 'consignment_purchase',
                    'change_field' => 'balance_available',
                    'change_value' => (string)(-$payFromBalance),
                    'before_value' => (string)$buyerBalanceAvailable,
                    'after_value' => (string)$buyerAfterBalance,
                    'remark' => '购买寄售藏品（可用余额）：' . $item['title'],
                    'extra' => json_encode([
                        'consignment_id' => $consignmentId,
                        'order_no' => $orderNo,
                        'order_id' => $orderId,
                        'item_id' => $itemId,
                        'item_title' => $item['title'],
                        'seller_id' => $sellerId,
                        'buyer_id' => $buyerId,
                        'pay_from_balance' => $payFromBalance,
                        'pay_from_withdrawable' => $payFromWithdrawable,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
            
            if ($payFromWithdrawable > 0) {
                Db::name('user_activity_log')->insert([
                    'user_id' => $buyerId,
                    'related_user_id' => $sellerId,
                    'action_type' => 'consignment_purchase',
                    'change_field' => 'withdrawable_money',
                    'change_value' => (string)(-$payFromWithdrawable),
                    'before_value' => (string)$buyerWithdrawableMoney,
                    'after_value' => (string)$buyerAfterWithdrawable,
                    'remark' => '购买寄售藏品（可提现余额）：' . $item['title'],
                    'extra' => json_encode([
                        'consignment_id' => $consignmentId,
                        'order_no' => $orderNo,
                        'order_id' => $orderId,
                        'item_id' => $itemId,
                        'item_title' => $item['title'],
                        'seller_id' => $sellerId,
                        'buyer_id' => $buyerId,
                        'pay_from_balance' => $payFromBalance,
                        'pay_from_withdrawable' => $payFromWithdrawable,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }

            // 记录卖家活动日志（可提现余额变动）
            Db::name('user_activity_log')->insert([
                'user_id' => $sellerId,
                'related_user_id' => $buyerId,
                'action_type' => 'consignment_income',
                'change_field' => 'withdrawable_money',
                'change_value' => (string)$sellerTotalWithdrawable,
                'before_value' => (string)$sellerBeforeWithdrawable,
                'after_value' => (string)$sellerAfterWithdrawable,
                'remark' => '寄售藏品售出结算（差价模式）：' . $item['title'] . '（本金：' . number_format($originalBuyPrice, 2) . '元，手续费退还：' . number_format($feeRefund, 2) . '元，利润余额：' . number_format($profitToBalance, 2) . '元）',
                'extra' => json_encode([
                    'consignment_id' => $consignmentId,
                    'order_no' => $orderNo,
                    'order_id' => $orderId,
                    'item_id' => $itemId,
                    'item_title' => $item['title'],
                    'seller_id' => $sellerId,
                    'buyer_id' => $buyerId,
                    'original_buy_price' => $originalBuyPrice,
                    'sale_price' => $consignmentPrice,
                    'profit' => $profit,
                    'fee_refund' => $feeRefund,
                    'remaining_profit' => $remainingProfit,
                    'profit_balance' => $profitToBalance,
                    'profit_score' => $profitToScore,
                    'is_old_asset_package' => $isOldAssetPackage ? 1 : 0,
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
                'update_time' => $now,
            ]);
            
            // 如果有积分变动，记录积分活动日志
            if ($profitToScore > 0) {
                Db::name('user_activity_log')->insert([
                    'user_id' => $sellerId,
                    'related_user_id' => $buyerId,
                    'action_type' => 'consignment_profit_score',
                    'change_field' => 'score',
                    'change_value' => (string)$profitToScore,
                    'before_value' => (string)$sellerBeforeScore,
                    'after_value' => (string)$sellerAfterScore,
                    'remark' => '寄售藏品售出利润积分：' . $item['title'] . '（利润积分：' . $profitToScore . '分）',
                    'extra' => json_encode([
                        'consignment_id' => $consignmentId,
                        'order_no' => $orderNo,
                        'order_id' => $orderId,
                        'item_id' => $itemId,
                        'item_title' => $item['title'],
                        'seller_id' => $sellerId,
                        'buyer_id' => $buyerId,
                        'profit' => $profit,
                        'profit_score' => $profitToScore,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }

            // ========== 代理商佣金分配 ==========
            // 佣金计算基数为卖家的利润
            if ($profit > 0) {
                $this->distributeAgentCommission($sellerId, $profit, $item['title'], $consignmentId, $orderNo, $orderId, $now);
            }

            Db::commit();

            return [
                'order_no' => $orderNo,
                'order_id' => $orderId,
                'total_amount' => $consignmentPrice,
            ];
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    // ============================================================
    // [2025-12-26] processConsignmentPurchaseWithMatching 已移除
    // 原因：不再使用 ba_collection_matching_pool 撮合池
    // 现在使用 ba_trade_reservations 盲盒预约系统
    // ============================================================

    #[
        Apidoc\Title("权益交割"),
        Apidoc\Tag("藏品商城,权益分割"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/collectionItem/rightsDeliver"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "user_collection_id", type: "int", require: true, desc: "用户藏品记录ID"),
    ]
    public function rightsDeliver(): void
    {
        $this->error('该接口已废弃');
    }

    /**
     * 根据订单ID获取用户藏品ID
     */
    private function getUserCollectionIdFromOrder(int $orderId, int $userId): int
    {
        if ($orderId <= 0) {
            return 0;
        }

        return (int)Db::name('user_collection')
            ->where('order_id', $orderId)
            ->where('user_id', $userId)
            ->value('id') ?: 0;
    }

    #[
        Apidoc\Title("申请寄售"),
        Apidoc\Tag("藏品商城,寄售"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/collectionItem/consign"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "user_collection_id", type: "int", require: true, desc: "用户藏品记录ID"),
        Apidoc\Param(name: "price", type: "float", require: false, desc: "寄售价格(可忽略，默认使用原价)"),
        Apidoc\Returned("consignment_id", type: "int", desc: "寄售记录ID"),
        Apidoc\Returned("user_collection_id", type: "int", desc: "用户藏品ID"),
        Apidoc\Returned("item_id", type: "int", desc: "商品ID"),
        Apidoc\Returned("consignment_price", type: "float", desc: "寄售价格"),
        Apidoc\Returned("service_fee", type: "float", desc: "服务费（从确权金扣除）"),
        Apidoc\Returned("is_free_resend", type: "bool", desc: "是否为免费重发"),
        Apidoc\Returned("waive_type", type: "string", desc: "豁免类型：none=未豁免，system_resend=系统重发，free_attempt=免费次数"),
        Apidoc\Returned("coupon_used", type: "bool", desc: "是否使用了寄售券"),
        Apidoc\Returned("coupon_deducted", type: "int", desc: "扣除的寄售券数量"),
        Apidoc\Returned("coupon_remaining", type: "int", desc: "剩余可用寄售券数量"),
        Apidoc\Returned("free_at attempts_used", type: "int", desc: "是否使用了免费寄售次数（0或1）"),
        Apidoc\Returned("free_attempts_remaining", type: "int", desc: "剩余免费寄售次数"),
        Apidoc\Returned("item_title", type: "string", desc: "藏品标题"),
        Apidoc\Returned("price_zone", type: "string", desc: "价格分区"),
        Apidoc\Returned("session_id", type: "int", desc: "场次ID"),
        Apidoc\Returned("zone_id", type: "int", desc: "价格区间ID"),
        Apidoc\Returned("package_name", type: "string", desc: "资产包名称"),
        Apidoc\Returned("listed_at", type: "string", desc: "上架时间"),
        Apidoc\Returned("expire_at", type: "string", desc: "过期时间（7天后）"),
    ]
    public function consign(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;
        $userCollectionId = $this->request->param('user_collection_id/d', 0);


        if (!$userCollectionId) {
            $this->error('参数错误：缺少用户藏品ID');
        }

        Db::startTrans();
        try {
            // 1. 校验用户
            $user = Db::name('user')
                ->where('id', $userId)
                ->lock(true)
                ->find();
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            // 2. 校验用户藏品记录
            $collection = Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();
            
            // 如果通过ID找不到，尝试通过order_id查找（兼容处理）
            if (!$collection) {
                $collection = Db::name('user_collection')
                    ->where('order_id', $userCollectionId)
                    ->where('user_id', $userId)
                    ->lock(true)
                    ->find();
                
                if ($collection) {
                    // 更新 userCollectionId 为正确的ID
                    $userCollectionId = (int)$collection['id'];
                }
            }
            
            if (!$collection) {
                throw new \Exception('藏品记录不存在');
            }

            // 检查藏品是否已进行权益交割，已权益交割的藏品不能寄售
            // 使用 LIKE 查询替代 whereJsonContains，避免可能的JSON解析问题
            $rightsDistributed = Db::name('user_activity_log')
                ->where('user_id', $userId)
                ->where('action_type', 'rights_distribute')
                ->where('extra', 'like', '%"user_collection_id":' . $userCollectionId . '%')
                ->find();
            if ($rightsDistributed) {
                throw new \Exception('该藏品已进行权益交割，无法寄售');
            }


            // ⚠️ 关键保护：检查藏品是否处于"收益中"状态
            // mining_status=1 表示矿机分红中，不可寄售
            // 防止用户在获得分红的同时又卖出藏品
            $miningStatus = (int)($collection['mining_status'] ?? 0);
            if ($miningStatus === 1) {
                throw new \Exception('该藏品当前为矿机状态（正在产生收益），无法寄售');
            }
            
            // TODO: 如果将来添加 rights_status 字段（权益分红状态），也需要检查
            // $rightsStatus = (int)($collection['rights_status'] ?? 0);
            // if ($rightsStatus === 1) {
            //     throw new \Exception('该藏品当前处于权益收益中，无法寄售');
            // }

            if ((int)$collection['delivery_status'] !== 0) {
                throw new \Exception('已提货的藏品不能寄售');
            }
            $consStatus = (int)$collection['consignment_status'];
            if ($consStatus !== 0) {
                if ($consStatus === 1) {
                    throw new \Exception('该藏品当前正在寄售中，无法再次寄售');
                } elseif ($consStatus === 2) {
                    throw new \Exception('该藏品已售出，无法寄售');
                } else {
                    throw new \Exception('该藏品当前状态不允许寄售（状态码：' . $consStatus . '）');
                }
            }
            $buyTime = (int)$collection['buy_time'];
            // 从系统配置读取寄售解锁小时数（必须在后台配置，否则不允许使用默认硬编码）
            // 0 表示购买后即可寄售
            $unlockHoursRaw = get_sys_config('consignment_unlock_hours');
            if ($unlockHoursRaw === null || $unlockHoursRaw === '' || !is_numeric($unlockHoursRaw)) {
                throw new \Exception('系统未配置寄售解锁小时数，请在后台寄售配置中设置（小时）');
            }
            $unlockHours = (int)$unlockHoursRaw;
            if ($unlockHours < 0) {
                throw new \Exception('寄售解锁小时数配置无效，请在后台重新设置');
            }
            // 如果 unlockHours = 0，表示购买后即可寄售，跳过时间检查
            if ($unlockHours > 0 && $buyTime) {
                $unlockTime = $buyTime + $unlockHours * 3600;
                if (time() < $unlockTime) {
                    $remain = $unlockTime - time();
                    $hours = ceil($remain / 3600);
                    throw new \Exception('购买' . $unlockHours . '小时后才允许寄售，剩余约 ' . $hours . ' 小时');
                }
            }

            // 获取商品信息
            $item = Db::name('collection_item')->where('id', $collection['item_id'])->find();
            
            // 异常处理：如果由于某些原因（如商品被删除）导致找不到原始商品信息，
            // 尝试使用 user_collection 表中的快照信息作为兜底，避免流程阻塞
            if (!$item) {
                // 构造一个虚拟的 item 对象
                $item = [
                    'id' => $collection['item_id'], // 注意：这个ID实际上是不存在的
                    'title' => $collection['title'],
                    'image' => $collection['image'],
                    'price' => $collection['price'], // 使用当时的买入价
                    'stock' => 0, // 无法确定的库存
                    'status' => 1, // 假定可用
                    'price_zone' => $this->getPriceZone((float)$collection['price']), // 重新计算分区
                    'package_id' => 0,
                    'package_name' => '',
                ];
                // 记录警告日志
                // Db::name('system_log')->insert([...]); 
            }

            // 寄售价统一按照藏品当前最新价格（增值后的价格）
            $consignmentPrice = (float)($item['price'] ?? 0);
            if ($consignmentPrice <= 0) {
                 // 异常回退：若商品表价格无效，使用用户持有成本
                 $consignmentPrice = (float)$collection['price'];
            }
            if ($consignmentPrice <= 0) {
                throw new \Exception('该藏品未配置售价，无法寄售');
            }
            $itemPriceZone = $item['price_zone'] ?? $this->getPriceZone($consignmentPrice);

            // 确保 price_zone 始终是字符串，避免数组访问错误
            if (is_array($itemPriceZone)) {
                $itemPriceZone = $itemPriceZone[0] ?? '';
            }
            $itemPriceZone = (string)$itemPriceZone;

            $now = time();
            
            // 获取藏品标题用于日志
            $itemTitle = $collection['title'] ?? '';
            if (empty($itemTitle)) {
                $itemTitle = $item['title'] ?? '藏品寄售';
            }
            
            // ========== 检查免券资格（严格控制，只看最近一条寄售记录） ==========
            // ✅ 修复：只检查该藏品的最近一条寄售记录，而不是历史上任何流拍记录
            // 这样可以防止：
            // 1. 历史老流拍被反复利用
            // 2. 一个藏品吃多次免券
            // 3. 最近一次成功后又能用历史流拍免券
            
            $lastConsignment = Db::name('collection_consignment')
                ->where('user_collection_id', $userCollectionId)
                ->order('id desc')  // 最近一条
                ->find();
            
            // 判断最近一条寄售记录是否符合免费重发条件
            $failedConsignment = null;
            if ($lastConsignment 
                && (int)$lastConsignment['status'] === 3  // 流拍/清退
                && (int)$lastConsignment['free_relist_used'] === 0  // 未使用过免费重发
            ) {
                // ✅ 最近一次是流拍且未使用免费重发资格 → 可以免费
                $failedConsignment = $lastConsignment;
            }
            
            $freeAttempts = (int)($collection['free_consign_attempts'] ?? 0);
            
            // 判断免券类型
            $waiveType = 'none';
            $isFreeResend = false;
            $useFreeAttempt = false;
            
            if ($failedConsignment) {
                // 有流拍记录且未使用过免费重发 → 免券
                $isFreeResend = true;
                $waiveType = 'system_resend';
            } elseif ($freeAttempts > 0) {
                // 有免费次数 → 免券
                $useFreeAttempt = true;
                $waiveType = 'free_attempt';
                
                // 立即扣减免费次数（在同一事务中）
                $updated = Db::name('user_collection')
                    ->where('id', $userCollectionId)
                    ->where('free_consign_attempts', '>', 0) // 二次确认
                    ->dec('free_consign_attempts', 1)
                    ->update(['update_time' => $now]);
                
                if ($updated <= 0) {
                    throw new \Exception('免费寄售次数已用完，请使用寄售券');
                }
            }
            
            $serviceFee = 0;
            $serviceFeeRate = 0;
            $usedCouponId = 0; // 使用的寄售券ID
            $couponUsed = 0; // 是否使用了券
            $couponWaived = $isFreeResend || $useFreeAttempt ? 1 : 0; // 是否豁免券
            
            // ========== 情况 A：首次寄售（正常上架） ==========
            // 如果不是免券，需要执行首次寄售的完整检查流程
            if (!$isFreeResend && !$useFreeAttempt) {
                // 1. 检查当前是否是开放场次时间
                $sessionId = (int)($item['session_id'] ?? 0);
                if ($sessionId > 0) {
                    $session = Db::name('collection_session')
                        ->where('id', $sessionId)
                        ->where('status', '1')
                        ->find();
                    
                    if ($session) {
                        $currentTime = date('H:i');
                        $startTime = $session['start_time'] ?? '';
                        $endTime = $session['end_time'] ?? '';
                        
                        // 判断当前时间是否在交易时间内
                        $isInTradingTime = $this->isTimeInRange($currentTime, $startTime, $endTime);

                        if (!$isInTradingTime) {
                            $sessionName = $session['title'] ?? '该专场';
                            throw new \Exception('交易场次未开启，' . $sessionName . '交易时间为 ' . $startTime . ' - ' . $endTime . '，请在场次开启后再进行寄售');
                        }
                    } else {
                        throw new \Exception('交易场次未开启或不存在，请等待场次开启后再进行寄售');
                    }
                } else {
                    throw new \Exception('该藏品未关联交易场次，无法寄售');
                }
                
                // 2. 检查用户余额是否充足（需 >= 商品当前价 × 3%）
                // 从配置读取服务费费率（默认3%）
                $serviceFeeRate = (float)(get_sys_config('consignment_service_fee_rate') ?? 0.03);
                if ($serviceFeeRate <= 0 || $serviceFeeRate > 1) {
                    // 费率必须在 0-1 之间，如果配置异常则使用默认值 3%
                    $serviceFeeRate = 0.03;
                }

                // 计算服务费（基础费率）
                $baseServiceFee = round($consignmentPrice * $serviceFeeRate, 2);
                
                // 检查用户是否是代理，如果是则应用服务费折扣
                $serviceFee = $baseServiceFee;
                $userType = (int)$user['user_type'];
                if ($userType >= 3) {
                    // user_type >= 3 表示代理，应用折扣
                    $serviceFeeDiscount = (float)(get_sys_config('agent_service_discount') ?? 1.0);
                    if ($serviceFeeDiscount >= 0 && $serviceFeeDiscount <= 1) {
                        $serviceFee = round($baseServiceFee * $serviceFeeDiscount, 2);
                    }
                }
                
                // 检查用户确权金余额是否足够支付服务费
                if ($user['service_fee_balance'] < $serviceFee) {
                    throw new \Exception('确权金不足，无法支付寄售手续费（' . number_format($serviceFee, 2) . '元），当前确权金：' . number_format($user['service_fee_balance'], 2) . '元');
                }
                
                // 3. ✅ 检查并扣除寄售券（关键修复）
                $itemSessionId = (int)($item['session_id'] ?? 0);
                
                // 🔧 修复：根据寄售价格获取正确的 zone_id，而不是使用藏品的 zone_id
                // 因为藏品的 zone_id 可能为 0（通用包），导致无法找到可用券
                $zone = $this->getOrCreateZoneByPrice($consignmentPrice);
                $targetZoneId = (int)($zone['id'] ?? 0);
                
                // 如果根据价格获取的 zone_id 无效，尝试使用藏品的 zone_id 或 price_zone
                if ($targetZoneId <= 0) {
                    $itemZoneId = (int)($item['zone_id'] ?? 0);
                    
                    // 尝试补全 zone_id
                    if ($itemZoneId <= 0 && !empty($itemPriceZone)) {
                         $zoneMatch = Db::name('price_zone_config')->where('name', $itemPriceZone)->find();
                         if ($zoneMatch) {
                             $targetZoneId = (int)$zoneMatch['id'];
                         }
                    } else {
                        $targetZoneId = $itemZoneId;
                    }
                }

                $validCoupon = UserService::getAvailableCouponForConsignment($userId, $itemSessionId, $targetZoneId);

                if (!$validCoupon) {
                     $zoneName = $zone['name'] ?? ($itemPriceZone ?: "区间#{$targetZoneId}");
                     throw new \Exception("没有适用于该场次(#{$itemSessionId})和价格区间({$zoneName})的寄售券");
                }
                
                $usedCouponId = $validCoupon['id'];
                
                // ========== 执行扣费扣券（同一事务，确保原子性） ==========
                
                // 4. 扣除 3% 服务费（确权金）
                $beforeServiceFee = (float)$user['service_fee_balance'];
                $afterServiceFee = $beforeServiceFee - $serviceFee;
                Db::name('user')
                    ->where('id', $userId)
                    ->update([
                        'service_fee_balance' => $afterServiceFee,
                        'update_time' => $now,
                    ]);

                // 生成流水号和批次号（临时使用 user_collection_id，稍后会更新为 consignment_id）
                $flowNo = generateFlowNo();
                $tempBatchNo = generateBatchNo('CONSIGN_TEMP', $userCollectionId);
                
                // 记录余额日志（带业务关联字段）
                Db::name('user_money_log')->insert([
                    'flow_no' => $flowNo,
                    'batch_no' => $tempBatchNo, // 临时批次号，创建寄售记录后会更新
                    'biz_type' => 'consign_apply_fee',
                    'biz_id' => 0, // 将在创建寄售记录后更新为 consignment_id
                    'user_collection_id' => $userCollectionId,
                    'item_id' => (int)$collection['item_id'],
                    'title_snapshot' => $itemTitle,
                    'image_snapshot' => $item['image'] ?? '',
                    'user_id' => $userId,
                    'field_type' => 'service_fee_balance',
                    'money' => -$serviceFee,
                    'before' => $beforeServiceFee,
                    'after' => $afterServiceFee,
                    'memo' => '寄售手续费：' . $itemTitle,
                    'extra_json' => json_encode([
                        'consignment_price' => $consignmentPrice,
                        'service_fee' => $serviceFee,
                        'service_fee_rate' => $serviceFeeRate,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                ]);

                // 记录活动日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'consignment_fee',
                    'change_field' => 'service_fee_balance',
                    'change_value' => (string)(-$serviceFee),
                    'before_value' => (string)$beforeServiceFee,
                    'after_value' => (string)$afterServiceFee,
                    'remark' => '寄售手续费：' . $itemTitle,
                    'extra' => json_encode([
                        'consignment_price' => $consignmentPrice,
                        'service_fee' => $serviceFee,
                        'service_fee_rate' => $serviceFeeRate,
                        'user_collection_id' => $userCollectionId,
                        'is_free_resend' => false,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                // 5. ✅ 扣除寄售券（必须检查返回值，确保成功）
                try {
                    $couponSuccess = UserService::useCoupon($usedCouponId, $userId);
                    if (!$couponSuccess) {
                        throw new \Exception('寄售券扣除失败，请重试');
                    }
                    $couponUsed = 1;
                } catch (\Exception $e) {
                    // 扣券失败，抛出异常回滚整个事务
                    throw new \Exception('寄售券扣除失败：' . $e->getMessage());
                }
                
                // 记录活动日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'consignment_coupon_use',
                    'change_field' => 'consignment_coupon',
                    'change_value' => '-1',
                    'before_value' => '1',
                    'after_value' => '0',
                    'remark' => '使用寄售券：' . $itemPriceZone . '（寄售：' . $itemTitle . '）',
                    'extra' => json_encode([
                        'coupon_id' => $usedCouponId,
                        'price_zone' => $itemPriceZone,
                        'user_collection_id' => $userCollectionId,
                        'item_title' => $itemTitle,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            } else {
                // ========== 情况 B：免费寄售（系统重发或免费次数） ==========
                // 仍然需要检查场次时间
                $sessionId = (int)($item['session_id'] ?? 0);
                if ($sessionId > 0) {
                    $session = Db::name('collection_session')
                        ->where('id', $sessionId)
                        ->where('status', '1')
                        ->find();
                    
                    if ($session) {
                        $currentTime = date('H:i');
                        $startTime = $session['start_time'] ?? '';
                        $endTime = $session['end_time'] ?? '';
                        
                        // 判断当前时间是否在交易时间内
                        $isInTradingTime = $this->isTimeInRange($currentTime, $startTime, $endTime);

                        if (!$isInTradingTime) {
                            $sessionName = $session['title'] ?? '该专场';
                            throw new \Exception('交易场次未开启，' . $sessionName . '交易时间为 ' . $startTime . ' - ' . $endTime . '，请在场次开启后再进行寄售');
                        }
                    } else {
                        throw new \Exception('交易场次未开启或不存在，请等待场次开启后再进行寄售');
                    }
                } else {
                    throw new \Exception('该藏品未关联交易场次，无法寄售');
                }
                
                // 执行：❌ 不扣服务费，❌ 不扣寄售券
                // （因为这是免费重发或使用免费次数）
                
                // 记录活动日志
                $actionType = $isFreeResend ? 'consignment_resend' : 'consignment_free_attempt';
                $remarkMsg = $isFreeResend 
                    ? '免费重发寄售（流拍后免费重新上架，不扣服务费和寄售券）'
                    : '使用免费寄售次数（不扣服务费和寄售券）';
                
                Db::name('user_activity_log')->insert([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => $actionType,
                    'change_field' => 'consignment_status',
                    'change_value' => '1',
                    'before_value' => '0',
                    'after_value' => '1',
                    'remark' => $remarkMsg,
                    'extra' => json_encode([
                        'consignment_price' => $consignmentPrice,
                        'user_collection_id' => $userCollectionId,
                        'is_free_resend' => $isFreeResend,
                        'use_free_attempt' => $useFreeAttempt,
                        'waive_type' => $waiveType,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }

            // ========== 生成寄售记录并更新状态 ==========
            // 6. 根据藏品的 package_id 和寄售价格分区匹配资产包
            // 🔧 修复：如果前面已经获取过 zone，直接使用，避免重复查询
            if (!isset($zone) || empty($zone)) {
                $zone = $this->getOrCreateZoneByPrice($consignmentPrice);
            }
            $zoneId = (int)($zone['id'] ?? 0);
            
            // 检查是否为旧资产包
            $isOldAssetPackage = (int)($collection['is_old_asset_package'] ?? 0);
            $package = null;
            
            // 从藏品记录读取 package_id 和 package_name
            $itemPackageId = (int)($item['package_id'] ?? 0);
            $itemPackageName = $item['package_name'] ?? '';
            
            if ($isOldAssetPackage === 1) {
                // ========== 旧资产包：随机混入 ==========
                // 优先在当前场次+当前价格分区查找可用的资产包
                $availablePackages = Db::name('asset_package')
                    ->where('session_id', $sessionId)
                    ->where('status', 1)
                    ->select()
                    ->toArray();
                
                if (!empty($availablePackages)) {
                    // 随机选择一个资产包混入
                    $randomIndex = array_rand($availablePackages);
                    $package = $availablePackages[$randomIndex];
                } else {
                    // 当前场次没有资产包，从其他场次获取模板创建
                    $templatePackage = Db::name('asset_package')
                        ->where('status', 1)
                        ->order('id asc')
                        ->find();
                    
                    // 获取场次信息
                    $sessionInfo = Db::name('collection_session')
                        ->where('id', $sessionId)
                        ->field('title')
                        ->find();
                    $sessionTitle = $sessionInfo ? $sessionInfo['title'] : '场次' . $sessionId;
                    
                    // 获取价格分区信息
                    $zoneInfo = Db::name('price_zone_config')
                        ->where('id', $zoneId)
                        ->find();
                    $zoneName = $zoneInfo ? $zoneInfo['name'] : '价格分区' . $zoneId;
                    
                    // 基于模板创建新资产包（统一设置为通用包，因为每个资产包都会有多个价格分区的商品）
                    $newPackageName = $templatePackage ? $templatePackage['name'] : ($sessionTitle . '-' . $zoneName);
                    $newPackageId = Db::name('asset_package')->insertGetId([
                        'session_id' => $sessionId,
                        'zone_id' => 0,  // 统一设置为通用包
                        'name' => $newPackageName,
                        'description' => '基于旧资产寄售自动创建',
                        'status' => 1,
                        'is_default' => 1,
                        'total_count' => 0,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    
                    if ($newPackageId) {
                        $package = Db::name('asset_package')
                            ->where('id', $newPackageId)
                            ->find();
                    }
                }
            } else {
                // ========== 普通藏品：使用藏品绑定的资产包 ==========
                
                // 步骤1：优先使用藏品的 package_id（直接关联）
                if ($itemPackageId > 0) {
                    $package = Db::name('asset_package')
                        ->where('id', $itemPackageId)
                        ->where('status', 1)
                        ->find();
                }
                
                // 步骤2：如果 package_id 无效，按 package_name 匹配
                if (!$package && !empty($itemPackageName)) {
                    $package = Db::name('asset_package')
                        ->where('name', $itemPackageName)
                        ->where('session_id', $sessionId)
                        ->where('status', 1)
                        ->find();
                }
                
                // 步骤3：如果仍然没有匹配，按场次查找任意可用的资产包
                if (!$package) {
                    $package = Db::name('asset_package')
                        ->where('session_id', $sessionId)
                        ->where('status', 1)
                        ->order('is_default desc, total_count asc, id asc')
                        ->find();
                }
                
                // 步骤4：如果该场次没有资产包，创建新的
                if (!$package) {
                    // 获取场次信息
                    $sessionInfo = Db::name('collection_session')
                        ->where('id', $sessionId)
                        ->field('title')
                        ->find();
                    $sessionTitle = $sessionInfo ? $sessionInfo['title'] : '场次' . $sessionId;
                    
                    // 获取价格分区信息
                    $zoneInfo = Db::name('price_zone_config')
                        ->where('id', $zoneId)
                        ->find();
                    $zoneName = $zoneInfo ? $zoneInfo['name'] : '价格分区' . $zoneId;
                    
                    // 创建新资产包（统一设置为通用包，因为每个资产包都会有多个价格分区的商品）
                    $newPackageId = Db::name('asset_package')->insertGetId([
                        'session_id' => $sessionId,
                        'zone_id' => 0,  // 统一设置为通用包
                        'name' => $sessionTitle . '-' . $zoneName,
                        'description' => '自动创建的资产包',
                        'status' => 1,
                        'is_default' => 1,
                        'total_count' => 0,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    
                    if ($newPackageId) {
                        $package = Db::name('asset_package')
                            ->where('id', $newPackageId)
                            ->find();
                    }
                }
            }
            
            $packageId = $package ? (int)$package['id'] : 0;
            $packageName = $package ? (string)$package['name'] : ($itemPackageName ?: '未归类');
            
            // 7. 生成寄售记录（商品上架到商城，包含资产包信息）
            // 🆕 保存卖家原购买价格用于利润计算
            $originalBuyPrice = (float)($collection['price'] ?? $consignmentPrice);
            
            // 🆕 旧资产包特殊处理：寄售时"更名"（关联到该资产包下的某个有效商品）
            $consignmentItemId = $collection['item_id'];
            if ($isOldAssetPackage === 1 && $packageId > 0) {
                // 尝试在目标资产包中找一个现有商品（作为模板）
                $targetItem = Db::name('collection_item')
                    ->where('package_id', $packageId)
                    ->where('status', 1)
                    ->order('id asc')
                    ->find();
                
                if ($targetItem) {
                    $consignmentItemId = $targetItem['id'];
                } else {
                    // 如果该资产包下没有商品（如新建的包），则创建一个同名商品
                    $newItemId = Db::name('collection_item')->insertGetId([
                        'session_id' => $sessionId,
                        'zone_id' => $zoneId,
                        'package_id' => $packageId,
                        'package_name' => $packageName,
                        'title' => $packageName, // 商品名 = 资产包名
                        'image' => $collection['image'] ?? '', // 沿用原图或默认图
                        'price' => $consignmentPrice,
                        'issue_price' => $consignmentPrice,
                        'price_zone' => $zone['name'] ?? '1000元区',
                        'status' => 1,
                        'stock' => 9999, // 虚拟库存
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    if ($newItemId) {
                        $consignmentItemId = $newItemId;
                    }
                }
            }

            $consignmentId = Db::name('collection_consignment')->insertGetId([
                'user_id'           => $userId,
                'user_collection_id'=> $userCollectionId,
                'item_id'           => $consignmentItemId,
                'session_id'        => $sessionId,  // ✅ 修复：添加场次ID
                'zone_id'           => $zoneId,     // ✅ 修复：添加价格分区ID
                'package_id'        => $packageId,
                'package_name'      => $packageName,
                'price'             => $consignmentPrice,
                'original_price'    => $originalBuyPrice, // 🆕 卖家原购买价格
                'service_fee'       => $serviceFee, // 记录服务费（用户实际成本 = price + service_fee）
                'coupon_used'       => $couponUsed, // ✅ 是否使用了券
                'coupon_waived'     => $couponWaived, // ✅ 是否豁免券
                'waive_type'        => $waiveType, // ✅ 豁免类型
                'coupon_id'         => $usedCouponId, // ✅ 使用的券ID
                'free_relist_used'  => 0, // 初始未使用免费重发资格
                'status'            => 1, // 1=寄售中
                'create_time'       => $now,
                'update_time'       => $now,
            ]);
            if (!$consignmentId) {
                throw new \Exception('创建寄售记录失败');
            }
            
            // 更新手续费流水的 batch_no 和 biz_id（如果有扣费）
            if ($serviceFee > 0 && isset($flowNo)) {
                $newBatchNo = generateBatchNo('CONSIGN', $consignmentId);
                Db::name('user_money_log')
                    ->where('flow_no', $flowNo)
                    ->update([
                        'batch_no' => $newBatchNo,
                        'biz_id' => $consignmentId,
                    ]);
            }
            
            // ✅ 如果使用了流拍免费重发，标记该流拍记录的免费重发资格已使用
            if ($isFreeResend && $failedConsignment) {
                Db::name('collection_consignment')
                    ->where('id', $failedConsignment['id'])
                    ->update([
                        'free_relist_used' => 1,
                        'update_time' => $now,
                    ]);
            }
            
            // 更新资产包统计
            if ($packageId > 0) {
                Db::name('asset_package')
                    ->where('id', $packageId)
                    ->inc('total_count', 1)
                    ->update(['update_time' => $now]);
            }

            // 7. 更新用户藏品寄售状态为【出售中】
            Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->update([
                    'consignment_status' => 1,
                    'update_time'        => $now,
                ]);

            // 8. 如果商品已下架，重新上架（因为用户正在寄售，商品应该可以查看和交易）
            if ($item && isset($item['status']) && $item['status'] == '0') {
                Db::name('collection_item')
                    ->where('id', $item['id'])
                    ->update([
                        'status' => '1',
                        'update_time' => $now,
                    ]);
            }

            Db::commit();

            // ✅ 统计用户剩余券数量（用于返回）
            $couponRemaining = UserService::getCouponCount($userId);
            $freeAttemptsRemaining = (int)Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->value('free_consign_attempts');

            $message = $isFreeResend 
                ? '免费重发寄售成功，已上架到寄售区' 
                : ($useFreeAttempt 
                    ? '使用免费寄售次数上架成功' 
                    : '寄售申请成功，已上架到寄售区');
            
            // ✅ 规范化返回字段
            $this->success($message, [
                'consignment_id' => (int)$consignmentId,
                'user_collection_id' => (int)$userCollectionId,
                'item_id' => (int)$consignmentItemId,
                'consignment_price' => $consignmentPrice,
                'service_fee' => $serviceFee,
                'is_free_resend' => $isFreeResend,
                'waive_type' => $waiveType, // ✅ 豁免类型
                'coupon_used' => (bool)$couponUsed, // ✅ 是否使用了券
                'coupon_deducted' => $couponUsed, // ✅ 扣了几张券
                'coupon_remaining' => $couponRemaining, // ✅ 剩余券数量
                'free_attempts_used' => $useFreeAttempt ? 1 : 0, // ✅ 是否使用免费次数
                'free_attempts_remaining' => $freeAttemptsRemaining, // ✅ 剩余免费次数
                'item_title' => $itemTitle,
                'price_zone' => $itemPriceZone,
                'session_id' => $sessionId ?? 0, // ✅ 场次ID
                'zone_id' => $zoneId ?? 0, // ✅ 区间ID
                'package_name' => $packageName,
                'listed_at' => date('Y-m-d H:i:s', $now), // ✅ 上架时间
                'expire_at' => date('Y-m-d H:i:s', $now + 7 * 86400), // ✅ 7天后过期
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            // ✅ 添加 rollback_reason 到返回数据中，便于排查
            $this->error($e->getMessage(), [
                'rollback_reason' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ]);
        }
    }

    #[
        Apidoc\Title("检查寄售解锁状态"),
        Apidoc\Tag("藏品商城,寄售"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/consignmentCheck"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "user_collection_id", type: "int", require: true, desc: "用户藏品记录ID"),
    ]
    public function consignmentCheck(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;
        $userCollectionId = $this->request->param('user_collection_id/d', 0);
        if (!$userCollectionId) {
            $this->error('参数错误：缺少用户藏品ID');
        }

        try {
            // 查询用户藏品记录，兼容 order_id 查找
            $collection = Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->where('user_id', $userId)
                ->find();
            if (!$collection) {
                $collection = Db::name('user_collection')
                    ->where('order_id', $userCollectionId)
                    ->where('user_id', $userId)
                    ->find();
                if ($collection) {
                    $userCollectionId = (int)$collection['id'];
                }
            }

            if (!$collection) {
                $this->error('藏品记录不存在或不属于当前用户');
            }

            $buyTime = (int)$collection['buy_time'];

            // 读取系统配置（不提供默认回退，鼓励后台显式设置）
            // 0 表示购买后即可寄售
            $unlockHoursRaw = get_sys_config('consignment_unlock_hours');
            $unlockHoursInt = is_numeric($unlockHoursRaw) ? (int)$unlockHoursRaw : null;

            $now = time();
            $canConsign = true;
            $remaining_seconds = 0;
            $unlock_time = null;
            $message = '';

            if (!$buyTime) {
                $message = '未查询到购买时间，无法判断是否满足寄售最短持有时长';
            }

            // 检查配置是否存在（null 表示未配置，0 表示购买后即可寄售）
            if ($unlockHoursInt === null) {
                // 未配置解锁小时数：提示管理员需要配置
                $canConsign = false;
                $message = '系统未配置寄售解锁小时数，请在后台寄售配置中设置（小时）';
            } elseif ($unlockHoursInt === 0) {
                // 配置为 0：购买后即可寄售
                $canConsign = true;
                $message = '购买后即可寄售';
            } elseif ($buyTime) {
                $unlock_time = $buyTime + $unlockHoursInt * 3600;
                if ($now < $unlock_time) {
                    $canConsign = false;
                    $remaining_seconds = $unlock_time - $now;
                    $hoursRem = ceil($remaining_seconds / 3600);
                    $message = '购买' . $unlockHoursInt . '小时后才允许寄售，剩余约 ' . $hoursRem . ' 小时';
                } else {
                    $canConsign = true;
                    $message = '已满足寄售时间限制';
                }
            }

            $this->success('', [
                'can_consign' => (bool)$canConsign,
                'unlock_hours' => $unlockHoursInt !== null ? $unlockHoursInt : null,
                'buy_time' => $buyTime,
                'unlock_time' => $unlock_time,
                'remaining_seconds' => (int)$remaining_seconds,
                'message' => $message,
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }

    // `consignmentList` 已合并到 `bySession`，此方法已移除。

    #[
        Apidoc\Title("寄售交易区列表"),
        Apidoc\Tag("藏品商城,寄售交易区"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/tradeList"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "10"),
        Apidoc\Query(name: "session_id", type: "int", require: false, desc: "专场ID"),
    ]
    public function tradeList(): void
    {
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $sessionId = $this->request->param('session_id/d', 0);
        $limit = min($limit, 50);

        // 只统计当前寄售中的记录（status = 1），按藏品聚合
        $query = Db::name('collection_consignment')
            ->alias('c')
            ->join('collection_item i', 'c.item_id = i.id', 'LEFT')
            ->where('c.status', 1)
            ->where('i.status', '1');

        if ($sessionId) {
            $query->where('i.session_id', $sessionId);
        }

        // 计算按藏品聚合后的总数
        $total = (clone $query)
            ->group('c.item_id')
            ->count();

        $list = $query
            ->field([
                'i.id',
                'i.session_id',
                'i.title',
                'i.image',
                // 使用寄售中最低价格作为展示价格
                Db::raw('MIN(c.price) AS price'),
                // 使用寄售中数量作为库存
                Db::raw('COUNT(*) AS stock'),
                // 寄售中暂不统计销量，这里固定为0，后续如有需要可扩展
                Db::raw('0 AS sales'),
            ])
            ->group('c.item_id')
            ->order('i.sort desc, i.id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['image'] = $item['image'] ? full_url($item['image'], false) : '';
            $item['price'] = (float)$item['price'];
            $item['stock'] = (int)$item['stock'];
            $item['sales'] = (int)$item['sales'];
        }

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[
        Apidoc\Title("我的寄售列表"),
        Apidoc\Tag("藏品商城,我的寄售"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/myConsignmentList"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "10"),
        Apidoc\Query(name: "status", type: "int", require: false, desc: "寄售状态: 0=全部, 1=寄售中, 2=已售出, 3=已取消"),
        Apidoc\Returned("list[].consignment_price", type: "float", desc: "寄售价格"),
        Apidoc\Returned("list[].service_fee", type: "float", desc: "服务费（从确权金扣除）"),
        Apidoc\Returned("list[].total_cost", type: "float", desc: "用户实际成本（寄售价格+服务费）"),
    ]
    public function myConsignmentList(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $status = $this->request->param('status/d', 0);
        $limit = min($limit, 50);

        $where = [
            ['c.user_id', '=', $userId],
        ];

        // 状态筛选：1=寄售中, 2=已售出, 3=流拍失败, 4=已取消
        // 注意：collection_consignment.status: 1=寄售中, 2=已售出, 3=流拍失败, 0=已取消
        // user_collection.consignment_status: 0=未寄售, 1=寄售中, 2=已售出
        if ($status > 0) {
            if ($status == 1) {
                // 寄售中：consignment.status = 1 且 user_collection.consignment_status = 1
                $where[] = ['c.status', '=', 1];
            } elseif ($status == 2) {
                // 已售出：consignment.status = 2 或 user_collection.consignment_status = 2
                $where[] = ['c.status', '=', 2];
            } elseif ($status == 3) {
                // 流拍失败：consignment.status = 3
                $where[] = ['c.status', '=', 3];
            } elseif ($status == 4) {
                // 已取消：consignment.status = 0
                $where[] = ['c.status', '=', 0];
            }
        }

        $query = Db::name('collection_consignment')
            ->alias('c')
            ->join('collection_item i', 'c.item_id = i.id', 'LEFT')
            ->join('user_collection uc', 'c.user_collection_id = uc.id', 'LEFT')
            ->where($where);

        $total = (clone $query)->count();

        $list = $query
            ->field([
                'c.id AS consignment_id',
                'c.user_id',
                'c.user_collection_id',
                'c.item_id',
                'c.price AS consignment_price',
                'c.service_fee', // 服务费
                'c.status AS consignment_status',
                'c.create_time',
                'c.update_time',
                'i.title',
                'i.image',
                'i.price AS original_price',
                'i.session_id',
                'uc.consignment_status AS user_collection_status',
            ])
            ->order('c.id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 状态映射
        $statusMap = [
            0 => '已取消',
            1 => '寄售中',
            2 => '已售出',
            3 => '已取消',
        ];

        foreach ($list as &$row) {
            $row['image'] = $row['image'] ? full_url($row['image'], false) : '';
            $row['original_price'] = isset($row['original_price']) ? (float)$row['original_price'] : 0.0;
            $row['consignment_price'] = (float)$row['consignment_price'];
            $row['service_fee'] = isset($row['service_fee']) ? (float)$row['service_fee'] : 0.0;
            // 用户实际成本 = 寄售价格 + 服务费
            $row['total_cost'] = $row['consignment_price'] + $row['service_fee'];
            $row['consignment_status'] = (int)$row['consignment_status'];
            $row['consignment_status_text'] = $statusMap[$row['consignment_status']] ?? '未知';
            $row['create_time_text'] = $row['create_time'] ? date('Y-m-d H:i:s', (int)$row['create_time']) : '';
            $row['update_time_text'] = $row['update_time'] ? date('Y-m-d H:i:s', (int)$row['update_time']) : '';
            
            // 计算寄售天数
            if ($row['create_time'] && $row['consignment_status'] == 1) {
                $daysPassed = (time() - (int)$row['create_time']) / (24 * 3600);
                $row['days_passed'] = floor($daysPassed);
                $row['can_force_delivery'] = $daysPassed >= 7; // 超过7天可强制提货
            } else {
                $row['days_passed'] = 0;
                $row['can_force_delivery'] = false;
            }
        }

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'per_page' => $limit,
            'current_page' => $page,
            'last_page' => $total ? (int)ceil($total / $limit) : 1,
            'has_more' => $page * $limit < $total,
        ]);
    }

    #[
        Apidoc\Title("寄售详情"),
        Apidoc\Tag("藏品商城,寄售"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/consignmentDetail"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "consignment_id", type: "int", require: true, desc: "寄售记录ID"),
        Apidoc\Returned("buyer_id", type: "int", desc: "买家用户ID（仅已售出时有值）"),
        Apidoc\Returned("buyer_username", type: "string", desc: "买家用户名（仅已售出时有值）"),
        Apidoc\Returned("buyer_nickname", type: "string", desc: "买家昵称（仅已售出时有值）"),
        Apidoc\Returned("buyer_mobile", type: "string", desc: "买家手机号（仅已售出时有值）"),
    ]
    public function consignmentDetail(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;
        $consignmentId = $this->request->param('consignment_id/d', 0);

        if (!$consignmentId) {
            $this->error('参数错误：缺少寄售记录ID');
        }

        $consignment = Db::name('collection_consignment')
            ->alias('c')
            ->join('collection_item i', 'c.item_id = i.id', 'LEFT')
            ->join('user_collection uc', 'c.user_collection_id = uc.id', 'LEFT')
            ->where('c.id', $consignmentId)
            ->where('c.user_id', $userId)
            ->field([
                'c.id AS consignment_id',
                'c.user_id',
                'c.user_collection_id',
                'c.item_id',
                'c.price AS consignment_price',
                'c.status AS consignment_status',
                'c.create_time',
                'c.update_time',
                'i.title',
                'i.image',
                'i.price AS original_price',
                'i.description',
                'i.artist',
                'i.session_id',
                'uc.consignment_status AS user_collection_status',
                'uc.delivery_status',
            ])
            ->find();

        if (!$consignment) {
            $this->error('寄售记录不存在或无权访问');
        }

        // 默认买家信息为空
        $consignment['buyer_id'] = 0;
        $consignment['buyer_username'] = '';
        $consignment['buyer_nickname'] = '';
        $consignment['buyer_mobile'] = '';

        // 如果寄售已售出，尝试查询买家信息
        if ((int)$consignment['consignment_status'] === 2) {
            // 通过寄售订单 remark 反查对应的买家订单
            $order = Db::name('collection_order')
                ->alias('o')
                ->where('o.remark', 'like', '寄售购买|consignment_id:' . $consignmentId . '|%')
                ->field(['o.user_id AS buyer_id'])
                ->order('o.id desc')
                ->find();

            if ($order && $order['buyer_id']) {
                $buyer = Db::name('user')
                    ->where('id', (int)$order['buyer_id'])
                    ->field(['id', 'username', 'nickname', 'mobile'])
                    ->find();

                if ($buyer) {
                    $consignment['buyer_id'] = (int)$buyer['id'];
                    // 出于隐私考虑，不返回买家用户名
                    $consignment['buyer_username'] = '';
                    $consignment['buyer_nickname'] = (string)$buyer['nickname'];
                    // 手机号脱敏处理：保留前三后四位，中间四位用*号代替
                    $mobile = (string)$buyer['mobile'];
                    if (preg_match('/^(\d{3})\d{4}(\d{4})$/', $mobile, $m)) {
                        $mobile = $m[1] . '****' . $m[2];
                    }
                    $consignment['buyer_mobile'] = $mobile;
                }
            }
        }

        // 处理图片
        $consignment['image'] = $consignment['image'] ? full_url($consignment['image'], false) : '';
        $consignment['original_price'] = (float)$consignment['original_price'];
        $consignment['consignment_price'] = (float)$consignment['consignment_price'];
        $consignment['consignment_status'] = (int)$consignment['consignment_status'];
        $consignment['user_collection_status'] = (int)$consignment['user_collection_status'];
        $consignment['delivery_status'] = (int)$consignment['delivery_status'];

        // 状态映射
        $statusMap = [
            0 => '已取消',
            1 => '寄售中',
            2 => '已售出',
            3 => '流拍失败',
        ];
        $consignment['consignment_status_text'] = $statusMap[$consignment['consignment_status']] ?? '未知';
        $consignment['create_time_text'] = $consignment['create_time'] ? date('Y-m-d H:i:s', (int)$consignment['create_time']) : '';
        $consignment['update_time_text'] = $consignment['update_time'] ? date('Y-m-d H:i:s', (int)$consignment['update_time']) : '';

        // 计算寄售天数
        if ($consignment['create_time'] && $consignment['consignment_status'] == 1) {
            $daysPassed = (time() - (int)$consignment['create_time']) / (24 * 3600);
            $consignment['days_passed'] = floor($daysPassed);
            $consignment['can_force_delivery'] = $daysPassed >= 7; // 超过7天可强制提货
            $consignment['remaining_days'] = $daysPassed < 7 ? ceil(7 - $daysPassed) : 0;
        } else {
            $consignment['days_passed'] = 0;
            $consignment['can_force_delivery'] = false;
            $consignment['remaining_days'] = 0;
        }

        $this->success('', $consignment);
    }

    #[
        Apidoc\Title("取消寄售"),
        Apidoc\Tag("藏品商城,寄售"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/collectionItem/cancelConsignment"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "consignment_id", type: "int", require: true, desc: "寄售记录ID"),
    ]
    public function cancelConsignment(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;
        $consignmentId = $this->request->param('consignment_id/d', 0);

        if (!$consignmentId) {
            $this->error('参数错误：缺少寄售记录ID');
        }

        Db::startTrans();
        try {
            // 1. 查询寄售记录
            $consignment = Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();

            if (!$consignment) {
                throw new \Exception('寄售记录不存在或无权操作');
            }

            // 2. 检查状态：只能取消寄售中的记录（status=1）
            // status=3（流拍失败）不允许取消，因为已经失败且不退还费用
            if ((int)$consignment['status'] !== 1) {
                if ((int)$consignment['status'] === 3) {
                    throw new \Exception('该寄售已流拍失败，无法取消');
                }
                throw new \Exception('只能取消寄售中的记录');
            }

            $userCollectionId = (int)$consignment['user_collection_id'];
            $now = time();

            // 3. 更新寄售记录状态为已取消（使用0表示已取消）
            Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->update([
                    'status' => 0, // 0表示已取消
                    'update_time' => $now,
                ]);

            // 4. 更新用户藏品寄售状态为未寄售
            Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->where('user_id', $userId)
                ->update([
                    'consignment_status' => 0, // 0=未寄售
                    'update_time' => $now,
                ]);

            // 5. 退还寄售券
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'consignment_coupon' => Db::raw('consignment_coupon + 1'),
                    'update_time' => $now,
                ]);

            Db::commit();

            $this->success('取消寄售成功，寄售券已退还');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("我的藏品列表"),
        Apidoc\Tag("藏品商城,我的藏品"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/myCollection"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Query(name: "status", type: "string", require: false, desc: "状态筛选: all=全部, holding=待寄售/持有中(默认), consigned=寄售中, failed=寄售失败, sold=已售出"),

        Apidoc\Returned("list[].id", type: "int", desc: "用户藏品ID"),
        Apidoc\Returned("list[].unique_id", type: "string", desc: "唯一标识ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "藏品标题"),
        Apidoc\Returned("list[].image", type: "string", desc: "藏品图片"),
        Apidoc\Returned("list[].asset_code", type: "string", desc: "确权编号"),
        Apidoc\Returned("list[].hash", type: "string", desc: "藏品唯一哈希标识（来源：collection_item.tx_hash，用于唯一性校验与展示）"),
        Apidoc\Returned("list[].price", type: "float", desc: "买入价格"),
        Apidoc\Returned("list[].market_price", type: "float", desc: "当前市场价"),
        Apidoc\Returned("list[].transaction_count", type: "int", desc: "交易次数"),
        Apidoc\Returned("list[].fail_count", type: "int", desc: "流拍次数"),
        Apidoc\Returned("list[].consignment_status", type: "int", desc: "寄售状态: 0=未寄售, 1=寄售中, 2=已售出"),
        Apidoc\Returned("list[].session_id", type: "int", desc: "场次ID (来源: ba_collection_item.session_id)"),
        Apidoc\Returned("list[].session_title", type: "string", desc: "场次标题 (来源: ba_collection_session.title)"),
        Apidoc\Returned("list[].session_start_time", type: "string", desc: "场次开始时间"),
        Apidoc\Returned("list[].session_end_time", type: "string", desc: "场次结束时间"),
        Apidoc\Returned("list[].zone_id", type: "int", desc: "价格区间ID (来源: ba_collection_item.zone_id)"),
        Apidoc\Returned("list[].price_zone", type: "string", desc: "价格分区名称 (来源: ba_collection_item.price_zone，如'1K区')"),
        Apidoc\Returned("list[].price_zone_calc", type: "int", desc: "是否由后端计算兜底 (0=数据库值/1=计算值)"),
        Apidoc\Returned("list[].mining_status", type: "int", desc: "矿机状态：0=否,1=是"),
        Apidoc\Returned("list[].mining_start_time", type: "string", desc: "矿机启动时间"),
    ]
    public function myCollection(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $status = $this->request->param('status', 'holding');

        // 已售出/寄售失败记录：从 collection_consignment 表查询
        if ($status === 'sold' || $status === 'failed') {
            $query = Db::name('collection_consignment')
                ->alias('cc')
                ->join('user_collection uc', 'cc.user_collection_id = uc.id', 'LEFT')
                ->join('collection_item i', 'cc.item_id = i.id', 'LEFT')
                ->join('collection_session s', 'i.session_id = s.id', 'LEFT')
                ->leftJoin('price_zone_config pzc', 'i.zone_id = pzc.id')
                ->where('cc.user_id', $userId);
            
            if ($status === 'sold') {
                $query->where('cc.status', 2); // 2=已售出
            } else {
                $query->where('cc.status', 3); // 3=流拍失败
            }

            $list = $query
                ->field([
                    'cc.id as consignment_id',
                    'cc.user_collection_id',
                    'cc.price as consignment_price',
                    'cc.service_fee',
                    'cc.sold_time',
                    'cc.settle_status',
                    'cc.settle_time',
                    'cc.service_fee_paid_at_apply',
                    'cc.settle_rule',
                    'cc.is_legacy_snapshot',
                    'cc.legacy_unlock_price_snapshot',
                    'cc.principal_amount',
                    'cc.profit_amount',
                    'cc.payout_principal_withdrawable',
                    'cc.payout_principal_consume',
                    'cc.payout_profit_withdrawable',
                    'cc.payout_profit_consume',
                    'cc.payout_total_withdrawable',
                    'cc.payout_total_consume',
                    'cc.create_time',
                    
                    'i.title',
                    'i.image',
                    'i.asset_code',
                    'i.tx_hash as hash',
                    'uc.rights_hash',
                    'uc.price as original_buy_price', // 原始购入价格
                    'i.session_id',
                    'i.zone_id',
                    
                    's.title as session_title',
                    'pzc.name as zone_name',
                ])
                ->order('cc.create_time desc')
                ->page($page, $limit)
                ->select()
                ->toArray();

            $total = (clone $query)->count();

            // 格式化已售出/流拍记录
            foreach ($list as &$item) {
                $item = $this->formatSoldConsignmentRecord($item);
                // 添加状态标识
                if ($status === 'failed') {
                    $item['consignment_status'] = 3;
                    $item['consignment_status_text'] = '寄售失败';
                }
            }
        } else {
            // 持有中/寄售中/全部：从 user_collection 表查询
            $query = Db::name('user_collection')
                ->alias('uc')
                ->join('collection_item i', 'uc.item_id = i.id', 'LEFT')
                ->join('collection_session s', 'i.session_id = s.id', 'LEFT')
                ->where('uc.user_id', $userId);

            // 状态筛选
            if ($status === 'holding') {
                // 持有中：未发货且未售出（consignment_status != 2）
                $query->where('uc.consignment_status', '<>', 2)
                      ->where('uc.delivery_status', 0);
            } elseif ($status === 'consigned') {
                // 寄售中
                $query->where('uc.consignment_status', 1);
            }
            
            if ($status === 'all') {
                // all 状态：包含持有中、寄售中，但不包含已售出（已售出单独查询）
                $query->where('uc.consignment_status', '<>', 2)
                      ->where('uc.delivery_status', 0);
            } elseif ($status === 'holding') {
                // 排除已售出的 (已售出代表所有权已转移)
                $query->where('uc.consignment_status', '<>', 2);
                // 排除已提货的 (已实体交割)
                $query->where('uc.delivery_status', 0);
            }

            $list = $query
                ->field([
                    'uc.id',
                    'uc.item_id',
                    'uc.price',     // 用户持仓价格（买入价）
                    'uc.create_time',
                    'uc.buy_time',
                    'uc.consignment_status',
                    'uc.delivery_status',
                    'uc.rights_hash',     // [NEW]
                    'uc.rights_status',   // [NEW]
                    'uc.contract_no',
                    'uc.block_height',
                    'uc.mining_status',   // [NEW] 矿机状态
                    'uc.mining_start_time', // [NEW] 矿机启动时间
                    
                    'i.title',
                    'i.image',
                    'i.artist',
                    'i.asset_code', 
                    'i.tx_hash as hash',
                    'i.price as market_price', // 当前市场价
                    'i.sales as transaction_count', // 交易次数
                    
                    'i.session_id',
                    's.title as session_title',
                    's.start_time as session_start_time',
                    's.end_time as session_end_time',
                    
                    'i.zone_id',
                    'i.price_zone',
                ])
                ->order('uc.create_time desc')
                ->page($page, $limit)
                ->select()
                ->toArray();

            $total = $query->count();

            // 批量查询流拍次数
            $itemIds = array_column($list, 'item_id');
            $failCounts = [];
            if (!empty($itemIds)) {
                $failCountsResult = Db::name('collection_consignment')
                    ->whereIn('item_id', $itemIds)
                    ->where('status', 3) // 3=流拍
                    ->group('item_id')
                    ->column('count(*)', 'item_id');
                $failCounts = $failCountsResult;
            }

            foreach ($list as &$item) {
                $item['image'] = toFullUrl($item['image'] ?? '');
                $item['price'] = (float)$item['price'];
                $item['market_price'] = (float)$item['market_price'];

                // 购入价格字段优先级处理
                $buyPrice = null;
                if (isset($item['buy_price'])) {
                    $buyPrice = $item['buy_price'];
                } elseif (isset($item['principal_amount'])) {
                    $buyPrice = $item['principal_amount'];
                } elseif (isset($item['price'])) {
                    $buyPrice = $item['price'];
                } elseif (isset($item['original_price'])) {
                    $buyPrice = $item['original_price'];
                } elseif (isset($item['original_record'])) {
                    $originalRecord = $item['original_record'];
                    if (isset($originalRecord['buy_price'])) {
                        $buyPrice = $originalRecord['buy_price'];
                    } elseif (isset($originalRecord['principal_amount'])) {
                        $buyPrice = $originalRecord['principal_amount'];
                    } elseif (isset($originalRecord['price'])) {
                        $buyPrice = $originalRecord['price'];
                    }
                }

                // 确保 buy_price 是数字或字符串类型
                if ($buyPrice !== null) {
                    $item['buy_price'] = is_numeric($buyPrice) ? (float)$buyPrice : (string)$buyPrice;
                } else {
                    // 如果都没有，使用当前的 price 作为兜底
                    $item['buy_price'] = $item['price'];
                }
                
                // 字段映射以满足前端需求
                $item['unique_id'] = (string)$item['id'];
                
                // [NEW] Hash 统一回退逻辑
                $realHash = $item['rights_hash'] ?? null;
                if (empty($realHash)) {
                    $realHash = $item['hash'] ?? null;
                }
                if (empty($realHash)) {
                    $realHash = md5($item['id'] . 'USER_COLLECTION_SALT_2025');
                }
                $item['hash'] = $realHash;

                // 资产编号逻辑（直接使用 collection_item.asset_code）
                $item['asset_code'] = $item['asset_code'] ?? '';
                
                $item['transaction_count'] = (int)($item['transaction_count'] ?? 0);
                $item['fail_count'] = (int)($failCounts[$item['item_id']] ?? 0);
                
                // price_zone 优先使用 item 表自带字段（最权威）
                if (empty($item['price_zone'])) {
                    // 兜底：用旧逻辑计算一个展示用分区
                    $item['price_zone'] = $this->getPriceZone($item['market_price']);
                    $item['price_zone_calc'] = 1; // 标识这是算出来的
                } else {
                    $item['price_zone_calc'] = 0;
                }
                
                // 格式化 session 和 zone 字段
                $item['session_id'] = (int)($item['session_id'] ?? 0);
                $item['zone_id'] = (int)($item['zone_id'] ?? 0);
                $item['session_title'] = $item['session_title'] ?? '';
                $item['session_start_time'] = $item['session_start_time'] ?? '';
                $item['session_end_time'] = $item['session_end_time'] ?? '';
                
                // 格式化状态文本
                $item['status_text'] = '持有中';
                if (isset($item['mining_status']) && $item['mining_status'] == 1) {
                    $item['status_text'] = '矿机运行中';
                } elseif ($item['consignment_status'] == 1) {
                    $item['status_text'] = '寄售中';
                }
                
                // 格式化时间
                $item['create_time_text'] = date('Y-m-d H:i:s', $item['create_time']);
                $item['mining_start_time_text'] = !empty($item['mining_start_time']) ? date('Y-m-d H:i:s', $item['mining_start_time']) : '';
            }
        }

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'per_page' => $limit,
            'current_page' => $page,
            'last_page' => ceil($total / $limit),
        ]);
    }

    #[
        Apidoc\Title("购买记录列表"),
        Apidoc\Tag("藏品商城,我的订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/purchaseRecords"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "10"),
        Apidoc\Returned("list[].asset_code", type: "string", desc: "确权编号"),
        Apidoc\Returned("list[].fingerprint", type: "string", desc: "MD5存证指纹"),
        Apidoc\Returned("list[].user_collection_id", type: "int", desc: "用户藏品ID"),
    ]
    public function purchaseRecords(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $limit = min($limit, 50);

        $userId = $this->auth->id;

        $consignmentCoupon = (int)Db::name('user')
            ->where('id', $userId)
            ->value('consignment_coupon');

        // 使用原生SQL联合查询，合并普通订单和旧资产解锁记录
        $offset = ($page - 1) * $limit;
        
        // 1. 构建基础查询SQL
        // 普通订单
        $sqlOrder = "SELECT 
                o.id as order_id,
                o.order_no,
                o.total_amount,
                o.status,
                o.pay_type,
                o.pay_time,
                i.item_id,
                i.item_title,
                i.item_image,
                i.price,
                i.quantity,
                i.subtotal,
                ci.asset_code,
                ci.tx_hash as fingerprint,
                0 as is_unlock_record
            FROM ba_collection_order o
            JOIN ba_collection_order_item i ON o.id = i.order_id
            LEFT JOIN ba_collection_item ci ON i.item_id = ci.id
            WHERE o.user_id = :uid1";

        // 获取创世节点算力权益证的商品ID
        $templateItem = Db::name('collection_item')->where('title', '创世节点算力权益证')->find();
        $templateId = $templateItem ? $templateItem['id'] : 0;

        // 旧资产解锁（视为一种特殊订单）
        $sqlUnlock = "SELECT 
                u.id as order_id,
                CONCAT('UL', u.create_time) as order_no,
                u.consumed_gold as total_amount,
                'paid' as status,
                'activation_gold' as pay_type,
                u.create_time as pay_time,
                $templateId as item_id,
                '创世节点算力权益证' as item_title,
                '/assets/img/genesis.png' as item_image,
                u.consumed_gold as price,
                1 as quantity,
                u.consumed_gold as subtotal,
                MD5(CONCAT('genesis', u.id)) as asset_code,
                '' as fingerprint,
                1 as is_unlock_record
            FROM ba_user_old_assets_unlock u
            WHERE u.user_id = :uid2";

        // 2. 计算总数
        $countSql = "SELECT COUNT(*) as total FROM (($sqlOrder) UNION ALL ($sqlUnlock)) as t";
        $totalResult = Db::query($countSql, ['uid1' => $userId, 'uid2' => $userId]);
        $total = $totalResult[0]['total'];

        // 3. 查询分页数据
        $dataSql = "SELECT * FROM (($sqlOrder) UNION ALL ($sqlUnlock)) as t 
                   ORDER BY pay_time DESC 
                   LIMIT $offset, $limit";
        
        $list = Db::query($dataSql, ['uid1' => $userId, 'uid2' => $userId]);

        $statusMap = [
            'pending' => '待支付',
            'paid' => '已支付',
            'completed' => '已完成',
            'cancelled' => '已取消',
            'refunded' => '已退款',
        ];
        $payTypeMap = [
            'money' => '余额支付',
            'score' => '消费金支付',
            'activation_gold' => '旧资产解锁',
        ];
        
        // 获取所有订单ID，用于查询提货和寄售状态
        $orderIds = array_column($list, 'order_id');
        $userCollections = [];
        if (!empty($orderIds)) {
            $userCollections = Db::name('user_collection')
                ->whereIn('order_id', $orderIds)
                ->where('user_id', $userId)
                ->field('id, order_id, delivery_status, consignment_status, buy_time, item_id, free_consign_attempts')
                ->select()
                ->toArray();
        }
        
        // 获取已提货的藏品ID，查询对应的提货订单状态
        $deliveredCollectionIds = [];
        foreach ($userCollections as $uc) {
            if ((int)$uc['delivery_status'] === 1) {
                $deliveredCollectionIds[] = $uc['id'];
            }
        }
        
        $deliveryOrders = [];
        if (!empty($deliveredCollectionIds)) {
            // 获取已提货的藏品详细信息
            $deliveredCollectionsInfo = Db::name('user_collection')
                ->whereIn('id', $deliveredCollectionIds)
                ->where('user_id', $userId)
                ->field('id, title, order_id')
                ->select()
                ->toArray();
            
            // 构建标题到藏品ID的映射
            $titleToUcId = [];
            foreach ($deliveredCollectionsInfo as $ucInfo) {
                $titleToUcId[$ucInfo['title']] = $ucInfo['id'];
            }
            
            $deliveryOrderList = Db::name('shop_order')
                ->alias('so')
                ->join('shop_order_item soi', 'so.id = soi.order_id', 'LEFT')
                ->where('so.user_id', $userId)
                ->where('so.remark', 'like', '藏品提货：%')
                ->field('so.id, so.order_no, so.status, so.remark, so.ship_time, so.complete_time, so.create_time, soi.product_name')
                ->select()
                ->toArray();
            
            foreach ($deliveryOrderList as $do) {
                $matchedUcId = null;
                
                // 方法1: 尝试从备注中解析user_collection_id（新格式）
                if (preg_match('/user_collection_id:(\d+)/', $do['remark'], $matches)) {
                    $ucId = (int)$matches[1];
                    if (in_array($ucId, $deliveredCollectionIds)) {
                        $matchedUcId = $ucId;
                    }
                }
                
                // 方法2: 通过藏品标题匹配（兼容旧格式）
                if (!$matchedUcId) {
                    $collectionTitle = '';
                    // 从备注中提取标题
                    if (preg_match('/藏品提货：(.+?)(\|.*)?$/', $do['remark'], $titleMatches)) {
                        $collectionTitle = trim($titleMatches[1]);
                    }
                    // 如果备注中没有，从订单明细中提取
                    if (!$collectionTitle && !empty($do['product_name'])) {
                        if (preg_match('/藏品提货：(.+?)$/', $do['product_name'], $titleMatches)) {
                            $collectionTitle = trim($titleMatches[1]);
                        }
                    }
                    
                    // 通过标题匹配藏品ID
                    if ($collectionTitle && isset($titleToUcId[$collectionTitle])) {
                        $matchedUcId = $titleToUcId[$collectionTitle];
                    }
                }
                
                if ($matchedUcId && in_array($matchedUcId, $deliveredCollectionIds)) {
                    // 如果该藏品还没有对应的提货订单，或者这个订单更新，则保存
                    if (!isset($deliveryOrders[$matchedUcId]) || $do['create_time'] > $deliveryOrders[$matchedUcId]['create_time']) {
                        $deliveryOrders[$matchedUcId] = $do;
                    }
                }
            }
        }
        
        // 不需要单独查询寄售记录，user_collection 表中已有 consignment_status 字段
        // 但需要查询历史寄售记录，以判断藏品是否曾经寄售并售出
        $soldConsignments = [];
        if (!empty($userCollectionIds)) {
            $soldRecords = Db::name('collection_consignment')
                ->whereIn('user_collection_id', $userCollectionIds)
                ->where('status', 2)  // 已售出
                ->field('user_collection_id, id, status, update_time')
                ->select()
                ->toArray();
            
            foreach ($soldRecords as $record) {
                $soldConsignments[(int)$record['user_collection_id']] = $record;
            }
        }

        // 获取所有用户藏品ID，用于检查权益交割状态
        $userCollectionIds = array_column($userCollections, 'id');

        // 查询哪些藏品已经进行过权益交割
        $rightsDistributedRecords = [];
        if (!empty($userCollectionIds)) {
            $rightsRecords = Db::name('user_activity_log')
                ->where('user_id', $userId)
                ->where('action_type', 'rights_distribute')
                ->select()
                ->toArray();

            foreach ($rightsRecords as $record) {
                $extra = json_decode($record['extra'], true);
                if ($extra && isset($extra['user_collection_id']) && in_array((int)$extra['user_collection_id'], $userCollectionIds)) {
                    $rightsDistributedRecords[] = (int)$extra['user_collection_id'];
                }
            }
        }

        foreach ($list as &$row) {
            $row['item_image'] = $row['item_image'] ? full_url($row['item_image'], false) : '';
            $row['price'] = (float)$row['price'];
            $row['subtotal'] = (float)$row['subtotal'];
            $row['total_amount'] = (float)$row['total_amount'];
            $row['pay_time_text'] = $row['pay_time'] ? date('Y-m-d H:i:s', (int)$row['pay_time']) : '';
            $row['pay_type_text'] = $payTypeMap[$row['pay_type']] ?? $row['pay_type'];
            
            // 查找该订单对应的用户藏品
            $orderCollections = array_filter($userCollections, function($uc) use ($row) {
                return $uc['order_id'] == $row['order_id'];
            });
            // 如果存在对应的 user_collection，优先返回其 buy_time 作为购买时间展示，并返回 user_collection.id
            $row['buy_time_text'] = '';
            $row['user_collection_id'] = 0;
            // $row['asset_code'] = ''; // 已从SQL获取
            // $row['fingerprint'] = ''; // 已从SQL获取
            $row['asset_code'] = $row['asset_code'] ?? '';
            $row['fingerprint'] = $row['fingerprint'] ?? '';
            
            if (!empty($orderCollections)) {
                $firstUc = current($orderCollections);
                if (!empty($firstUc['buy_time'])) {
                    $row['buy_time_text'] = date('Y-m-d H:i:s', (int)$firstUc['buy_time']);
                }
                $row['user_collection_id'] = isset($firstUc['id']) ? (int)$firstUc['id'] : 0;
            }
            
            // Removed: N+1 query for asset_code
            
            // 判断订单状态（优先级：提货状态 > 寄售状态 > 权益交割 > 待提货待寄售）
            $statusText = '';
            $hasDelivered = false;
            $hasConsigned = false;
            $hasRightsDistributed = false;
            $deliveryStatuses = [];
            $consignmentStatuses = [];
            
            foreach ($orderCollections as $uc) {
                // 检查提货状态
                if ((int)$uc['delivery_status'] === 1) {
                    $hasDelivered = true;
                    // 已提货，查询提货订单状态
                    if (isset($deliveryOrders[$uc['id']])) {
                        $deliveryOrder = $deliveryOrders[$uc['id']];
                        $deliveryStatus = $deliveryOrder['status'];
                        $deliveryStatuses[] = $deliveryStatus;
                    }
                }

                // 检查寄售状态（直接使用 user_collection 的 consignment_status）
                if ((int)$uc['consignment_status'] === 1 || (int)$uc['consignment_status'] === 2) {
                    $hasConsigned = true;
                    $consignmentStatuses[] = (int)$uc['consignment_status'];
                }

                // 检查权益交割状态
                if (in_array((int)$uc['id'], $rightsDistributedRecords)) {
                    $hasRightsDistributed = true;
                }
            }
            
            // 设置订单状态文本（优先级：提货 > 寄售 > 权益交割 > 待提货待寄售）
            if ($hasDelivered) {
                // 已提货，显示提货状态
                if (!empty($deliveryStatuses)) {
                    $latestDeliveryStatus = end($deliveryStatuses);
                    // 检查是否有发货时间，如果有但状态还是paid，应该是shipped
                    $latestDeliveryOrder = null;
                    foreach ($orderCollections as $uc) {
                        if ((int)$uc['delivery_status'] === 1 && isset($deliveryOrders[$uc['id']])) {
                            $do = $deliveryOrders[$uc['id']];
                            if ($do['status'] === $latestDeliveryStatus) {
                                $latestDeliveryOrder = $do;
                                break;
                            }
                        }
                    }
                    
                    // 如果状态是paid但有ship_time，应该是shipped
                    if ($latestDeliveryStatus === 'paid' && $latestDeliveryOrder && !empty($latestDeliveryOrder['ship_time']) && $latestDeliveryOrder['ship_time'] > 0) {
                        $latestDeliveryStatus = 'shipped';
                    }
                    
                    switch ($latestDeliveryStatus) {
                        case 'paid':
                            $statusText = '待发货';
                            break;
                        case 'shipped':
                            $statusText = '待收货';
                            break;
                        case 'completed':
                            $statusText = '已签收';
                            break;
                        default:
                            $statusText = $statusMap[$latestDeliveryStatus] ?? $latestDeliveryStatus;
                    }
                } else {
                    // 已提货但没有找到提货订单，显示已提货
                    $statusText = '已提货';
                }
                $row['status_text'] = $statusText;
                $row['delivery_status'] = $statusText;
                $row['consignment_status'] = '';
            } elseif ($hasConsigned && !empty($consignmentStatuses)) {
                // 已寄售，显示寄售状态
                $latestConsignmentStatus = end($consignmentStatuses);
                $consignmentStatusMap = [
                    1 => '寄售中',
                    2 => '已售出',
                ];
                $statusText = $consignmentStatusMap[$latestConsignmentStatus] ?? '已寄售';
                $row['status_text'] = $statusText;
                $row['delivery_status'] = '';
                $row['consignment_status'] = $statusText;
            } elseif ($hasRightsDistributed) {
                // 已权益交割，显示权益交割状态
                $row['status_text'] = '已权益交割';
                $row['delivery_status'] = '';
                $row['consignment_status'] = '已权益交割';
            } else {
                // 检查是否有已售出的寄售记录
                $hasSoldConsignment = false;
                foreach ($orderCollections as $uc) {
                    if (isset($soldConsignments[$uc['id']])) {
                        $hasSoldConsignment = true;
                        break;
                    }
                }
                
                if ($hasSoldConsignment) {
                    // 曾经寄售并已售出
                    $row['status_text'] = '已售出';
                    $row['delivery_status'] = '';
                    $row['consignment_status'] = '已售出';
                } else {
                    // 未提货也未寄售也未权益交割，显示待寄售
                    $row['status_text'] = '待寄售';
                    $row['delivery_status'] = '';
                    $row['consignment_status'] = '';
                }
            }
            
            // 添加详细状态信息
            $row['free_consign_attempts'] = isset($firstUc['free_consign_attempts']) ? (int)$firstUc['free_consign_attempts'] : 0;
            $row['order_status'] = $row['status'];
            $row['order_status_text'] = $statusMap[$row['status']] ?? $row['status'];
        }

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'per_page' => $limit,
            'current_page' => $page,
            'last_page' => $total ? (int)ceil($total / $limit) : 1,
            'has_more' => $page * $limit < $total,
            'consignment_coupon' => $consignmentCoupon,
        ]);
    }

    #[
        Apidoc\Title("藏品提货列表"),
        Apidoc\Tag("藏品商城,提货记录"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/deliveryList"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "10"),
        Apidoc\Query(name: "status", type: "string", require: false, desc: "订单状态: paid=待发货, shipped=已发货, completed=已完成"),
    ]
    public function deliveryList(): void
    {
        $this->error('该接口已废弃');
    }

    /**
     * 判断当前时间是否在时间范围内
     */
    private function isTimeInRange(string $currentTime, string $startTime, string $endTime): bool
    {
        // 如果结束时间小于开始时间，说明跨天
        if ($endTime < $startTime) {
            return $currentTime >= $startTime || $currentTime <= $endTime;
        }
        return $currentTime >= $startTime && $currentTime <= $endTime;
    }

    /**
     * 根据分区ID获取分区名称
     * @param int $zoneId 分区ID
     * @return string|null 分区名称
     */
    private function getZoneNameById(int $zoneId): ?string
    {
        if ($zoneId <= 0) {
            return null;
        }

        $zone = Db::name('price_zone_config')
            ->where('id', $zoneId)
            ->where('status', '1')
            ->find();

        return $zone ? $zone['name'] : null;
    }

    /**
     * 根据价格获取价格分区
     * @param float|string $price 价格
     * @return string|null 价格分区：1K区、2K区、3K区、4K区
     */
    private function getPriceZone($price): ?string
    {
        $price = (float)$price;
        // 根据数据库分区配置返回分区名称
        $zone = Db::name('price_zone_config')
            ->where('status', '1')
            ->where('min_price', '<=', $price)
            ->where('max_price', '>=', $price)
            ->find();
        return $zone ? $zone['name'] : null;
    }

    /**
     * 根据价格获取或创建分区
     * 如果价格超过现有最高分区，自动创建新分区（每500元一个分区）
     * @param float $price 商品价格
     * @return array 分区配置数组
     */
    private function getOrCreateZoneByPrice(float $price): array
    {
        // 先尝试匹配现有分区
        $zone = Db::name('price_zone_config')
            ->where('status', '1')
            ->where('min_price', '<=', $price)
            ->where('max_price', '>=', $price)
            ->find();
        
        if ($zone) {
            return $zone;
        }
        
        // 没有匹配的分区，需要创建新分区
        // 计算新分区的价格范围（每500元一个分区）
        $zoneStep = 500;
        $zoneIndex = (int)ceil($price / $zoneStep);  // 向上取整确定分区
        $maxPrice = $zoneIndex * $zoneStep;
        $minPrice = ($zoneIndex - 1) * $zoneStep + 0.01;
        
        // 如果min_price为负数或小于0.01，调整为0.01
        if ($minPrice < 0.01) {
            $minPrice = 0.01;
        }
        
        $zoneName = $maxPrice . '元区';
        
        // 检查是否已存在同名分区（避免重复创建）
        $existingZone = Db::name('price_zone_config')
            ->where('name', $zoneName)
            ->find();
        
        if ($existingZone) {
            return $existingZone;
        }
        
        // 获取当前最低的sort值
        $minSort = Db::name('price_zone_config')->min('sort') ?: 10;
        
        // 创建新分区
        $now = time();
        $newZoneId = Db::name('price_zone_config')->insertGetId([
            'name' => $zoneName,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'status' => '1',
            'sort' => max(1, $minSort - 10),  // 新分区排序更靠后
            'create_time' => $now,
            'update_time' => $now,
        ]);
        
        // 返回新创建的分区
        return [
            'id' => $newZoneId,
            'name' => $zoneName,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'status' => '1',
        ];
    }


    /**
     * 分配代理佣金
     * @param int $sellerId 卖家ID
     * @param float $profit 利润（佣金计算基数）
     * @param string $itemTitle 商品标题
     * @param int $consignmentId 寄售记录ID
     * @param string $orderNo 订单号
     * @param int $orderId 订单ID
     * @param int $now 当前时间戳
     * @return void
     */
    private function distributeAgentCommission(int $sellerId, float $profit, string $itemTitle, int $consignmentId, string $orderNo, int $orderId, int $now): void
    {
        // 从配置读取佣金比例
        $directRate = (float)(get_sys_config('agent_direct_rate') ?? 0.10);
        $indirectRate = (float)(get_sys_config('agent_indirect_rate') ?? 0.05);
        $teamRates = [
            1 => (float)(get_sys_config('agent_team_level1') ?? 0.09),
            2 => (float)(get_sys_config('agent_team_level2') ?? 0.12),
            3 => (float)(get_sys_config('agent_team_level3') ?? 0.15),
            4 => (float)(get_sys_config('agent_team_level4') ?? 0.18),
            5 => (float)(get_sys_config('agent_team_level5') ?? 0.21),
        ];
        $sameLevelRate = (float)(get_sys_config('agent_same_level_rate') ?? 0.10); // 同级奖比例

        // 确保比例在有效范围内
        if ($directRate < 0 || $directRate > 1) {
            $directRate = 0.10;
        }
        if ($indirectRate < 0 || $indirectRate > 1) {
            $indirectRate = 0.05;
        }
        foreach ($teamRates as $level => &$rate) {
            if ($rate < 0 || $rate > 1) {
                $rate = 0.09 + ($level - 1) * 0.03; // 默认值
            }
        }
        unset($rate);
        if ($sameLevelRate < 0 || $sameLevelRate > 1) {
            $sameLevelRate = 0.10;
        }

        // 获取卖家信息
        $seller = Db::name('user')->where('id', $sellerId)->find();
        if (!$seller) {
            return;
        }

        // 1. 直推佣金：获取卖家的邀请人（直推）
        $directInviterId = (int)$seller['inviter_id'];
        $directInviter = null;
        if ($directInviterId > 0) {
            $directInviter = Db::name('user')
                ->where('id', $directInviterId)
                ->lock(true)
                ->find();
            
            if ($directInviter) {
                $directCommission = round($profit * $directRate, 2);
                if ($directCommission > 0) {
                    // 修复：直推佣金发放到可提现余额
                    $directBeforeWithdrawable = (float)$directInviter['withdrawable_money'];
                    $directAfterWithdrawable = round($directBeforeWithdrawable + $directCommission, 2);
                    
                    Db::name('user')
                        ->where('id', $directInviterId)
                        ->update([
                            'withdrawable_money' => $directAfterWithdrawable,
                            'update_time' => $now,
                        ]);

                    // 记录可提现余额变动日志
                    Db::name('user_money_log')->insert([
                        'user_id' => $directInviterId,
                        'money' => $directCommission,
                        'before' => $directBeforeWithdrawable,
                        'after' => $directAfterWithdrawable,
                        'memo' => '直推佣金：' . $itemTitle . '（利润：' . number_format($profit, 2) . '元，比例：' . ($directRate * 100) . '%）',
                        'create_time' => $now,
                    ]);

                    // 记录活动日志
                    Db::name('user_activity_log')->insert([
                        'user_id' => $directInviterId,
                        'related_user_id' => $sellerId,
                        'action_type' => 'agent_direct_commission',
                        'change_field' => 'withdrawable_money',
                        'change_value' => (string)$directCommission,
                        'before_value' => (string)$directBeforeWithdrawable,
                        'after_value' => (string)$directAfterWithdrawable,
                        'remark' => '直推佣金：' . $itemTitle . '（利润：' . number_format($profit, 2) . '元，比例：' . ($directRate * 100) . '%）',
                        'extra' => json_encode([
                            'seller_id' => $sellerId,
                            'profit' => $profit,
                            'commission_rate' => $directRate,
                            'commission_amount' => $directCommission,
                            'consignment_id' => $consignmentId,
                            'order_no' => $orderNo,
                            'order_id' => $orderId,
                            'item_title' => $itemTitle,
                        ], JSON_UNESCAPED_UNICODE),
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                }
            }
        }

        // 2. 间推佣金：获取直推的邀请人（间推）
        if ($directInviter && $directInviterId > 0) {
            $indirectInviterId = (int)($directInviter['inviter_id'] ?? 0);
            if ($indirectInviterId > 0) {
                $indirectInviter = Db::name('user')
                    ->where('id', $indirectInviterId)
                    ->lock(true)
                    ->find();
                
                if ($indirectInviter) {
                    $indirectCommission = round($profit * $indirectRate, 2);
                    if ($indirectCommission > 0) {
                        // 修复：间推佣金发放到可提现余额
                        $indirectBeforeWithdrawable = (float)$indirectInviter['withdrawable_money'];
                        $indirectAfterWithdrawable = round($indirectBeforeWithdrawable + $indirectCommission, 2);
                        
                        Db::name('user')
                            ->where('id', $indirectInviterId)
                            ->update([
                                'withdrawable_money' => $indirectAfterWithdrawable,
                                'update_time' => $now,
                            ]);

                        // 记录可提现余额变动日志
                        Db::name('user_money_log')->insert([
                            'user_id' => $indirectInviterId,
                            'money' => $indirectCommission,
                            'before' => $indirectBeforeWithdrawable,
                            'after' => $indirectAfterWithdrawable,
                            'memo' => '间推佣金：' . $itemTitle . '（利润：' . number_format($profit, 2) . '元，比例：' . ($indirectRate * 100) . '%）',
                            'create_time' => $now,
                        ]);

                        // 记录活动日志
                        Db::name('user_activity_log')->insert([
                            'user_id' => $indirectInviterId,
                            'related_user_id' => $sellerId,
                            'action_type' => 'agent_indirect_commission',
                            'change_field' => 'withdrawable_money',
                            'change_value' => (string)$indirectCommission,
                            'before_value' => (string)$indirectBeforeWithdrawable,
                            'after_value' => (string)$indirectAfterWithdrawable,
                            'remark' => '间推佣金：' . $itemTitle . '（利润：' . number_format($profit, 2) . '元，比例：' . ($indirectRate * 100) . '%）',
                            'extra' => json_encode([
                                'seller_id' => $sellerId,
                                'profit' => $profit,
                                'commission_rate' => $indirectRate,
                                'commission_amount' => $indirectCommission,
                                'consignment_id' => $consignmentId,
                                'order_no' => $orderNo,
                                'order_id' => $orderId,
                                'item_title' => $itemTitle,
                            ], JSON_UNESCAPED_UNICODE),
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);
                    }
                }
            }
        }

        // 3. 代理团队奖（累计制+同级特殊处理）：向上查找所有代理，按等级分配团队奖
        // 累计制：1级(9%) -> 2级(12%) -> 3级(15%) -> 4级(18%) -> 5级(21%)
        // 级差分配：1级拿9%，2级拿12%-9%=3%，3级拿15%-12%=3%，以此类推
        // 同级特殊处理：如果上级和下级是同一等级的代理，上级只拿10%的同级奖
        // 假设 user_type >= 3 表示代理，3=1级，4=2级，5=3级，6=4级，7=5级
        
        // 向上查找所有代理（最多向上查找10层），记录每个代理的等级和ID
        $agentChain = []; // [['user_id' => xxx, 'agent_level' => xxx], ...]
        $searchUserId = $sellerId;
        
        for ($searchDepth = 0; $searchDepth < 10; $searchDepth++) {
            $searchUser = Db::name('user')
                ->where('id', $searchUserId)
                ->find();
            
            if (!$searchUser) {
                break;
            }
            
            $inviterId = (int)$searchUser['inviter_id'];
            if ($inviterId <= 0) {
                break;
            }
            
            $inviter = Db::name('user')
                ->where('id', $inviterId)
                ->find();
            
            if (!$inviter) {
                break;
            }
            
            // 检查是否是代理（user_type >= 3 表示代理，3=1级，4=2级，5=3级，6=4级，7=5级）
            $agentLevel = (int)$inviter['user_type'] - 2; // user_type 3->1级, 4->2级, 5->3级, 6->4级, 7->5级
            
            if ($agentLevel >= 1 && $agentLevel <= 5) {
                $agentChain[] = [
                    'user_id' => $inviterId,
                    'agent_level' => $agentLevel,
                ];
            }
            
            $searchUserId = $inviterId;
        }
        
        // 按等级分组，记录每个等级第一次出现的代理
        $foundAgents = []; // [agentLevel => agentId]
        foreach ($agentChain as $agent) {
            $level = $agent['agent_level'];
            if (!isset($foundAgents[$level])) {
                $foundAgents[$level] = $agent['user_id'];
            }
        }
        
        // 按等级从低到高分配团队奖（累计制+同级特殊处理）
        $previousRate = 0;
        $previousLevel = 0;
        
        for ($level = 1; $level <= 5; $level++) {
            if (!isset($foundAgents[$level])) {
                continue; // 没找到该等级的代理，跳过
            }
            
            $agentId = $foundAgents[$level];
            
            // 判断是否是同级代理
            $isSameLevel = ($level == $previousLevel);
            
            if ($isSameLevel) {
                // 同级代理：只拿10%的同级奖
                $actualRate = $sameLevelRate;
                $commissionType = '同级奖';
            } else {
                // 不同级代理：按累计级差分配
                $currentRate = $teamRates[$level] ?? 0;
                $actualRate = $currentRate - $previousRate; // 级差：当前等级比例 - 上一等级比例
                $commissionType = '层级奖';
                $previousRate = $currentRate; // 更新上一等级的累计比例
            }
            
            $previousLevel = $level; // 更新上一个代理的等级
            
            if ($actualRate > 0) {
                $teamCommission = round($profit * $actualRate, 2);
                
                if ($teamCommission > 0) {
                    $agent = Db::name('user')
                        ->where('id', $agentId)
                        ->lock(true)
                        ->find();
                    
                    if ($agent) {
                        // 修复：代理团队奖发放到可提现余额
                        $teamBeforeWithdrawable = (float)$agent['withdrawable_money'];
                        $teamAfterWithdrawable = round($teamBeforeWithdrawable + $teamCommission, 2);
                        
                        Db::name('user')
                            ->where('id', $agentId)
                            ->update([
                                'withdrawable_money' => $teamAfterWithdrawable,
                                'update_time' => $now,
                            ]);

                        // 记录可提现余额变动日志
                        Db::name('user_money_log')->insert([
                            'user_id' => $agentId,
                            'money' => $teamCommission,
                            'before' => $teamBeforeWithdrawable,
                            'after' => $teamAfterWithdrawable,
                            'memo' => "{$level}级代理团队奖（{$commissionType}）：{$itemTitle}（利润：" . number_format($profit, 2) . "元，比例：" . ($actualRate * 100) . "%）",
                            'create_time' => $now,
                        ]);

                        // 记录活动日志
                        Db::name('user_activity_log')->insert([
                            'user_id' => $agentId,
                            'related_user_id' => $sellerId,
                            'action_type' => 'agent_team_commission',
                            'change_field' => 'withdrawable_money',
                            'change_value' => (string)$teamCommission,
                            'before_value' => (string)$teamBeforeWithdrawable,
                            'after_value' => (string)$teamAfterWithdrawable,
                            'remark' => "{$level}级代理团队奖（{$commissionType}）：{$itemTitle}（利润：" . number_format($profit, 2) . "元，比例：" . ($actualRate * 100) . "%）",
                            'extra' => json_encode([
                                'seller_id' => $sellerId,
                                'profit' => $profit,
                                'agent_level' => $level,
                                'commission_rate' => $actualRate,
                                'commission_type' => $commissionType,
                                'is_same_level' => $isSameLevel,
                                'commission_amount' => $teamCommission,
                                'consignment_id' => $consignmentId,
                                'order_no' => $orderNo,
                                'order_id' => $orderId,
                                'item_title' => $itemTitle,
                            ], JSON_UNESCAPED_UNICODE),
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * 商品被买走后，自动更新价格上涨（4%-6%随机）
     * @param int $itemId 商品ID
     * @param float $currentPrice 当前价格
     * @return void
     */
    private function updateItemPriceAfterPurchase(int $itemId, float $currentPrice): void
    {
        // 从配置读取价格上涨幅度（默认4%-6%）
        $minIncrease = (float)(get_sys_config('collection_price_increase_min') ?? 0.04);
        $maxIncrease = (float)(get_sys_config('collection_price_increase_max') ?? 0.06);

        // 确保配置值在合理范围内
        if ($minIncrease < 0 || $minIncrease > 1) {
            $minIncrease = 0.04;
        }
        if ($maxIncrease < 0 || $maxIncrease > 1) {
            $maxIncrease = 0.06;
        }
        if ($minIncrease > $maxIncrease) {
            // 如果最小值大于最大值，交换它们
            $temp = $minIncrease;
            $minIncrease = $maxIncrease;
            $maxIncrease = $temp;
        }

        // 生成随机涨幅（4%-6%之间，平均5.5%）
        $randomIncrease = $minIncrease + (mt_rand() / mt_getrandmax()) * ($maxIncrease - $minIncrease);
        
        // 计算目标净得金额（本金 + 增值）
        $targetNet = $currentPrice * (1 + $randomIncrease);

        // 读取寄售手续费率（默认3%）
        $serviceFeeRate = (float)(get_sys_config('consignment_service_fee_rate') ?? 0.03);
        if ($serviceFeeRate < 0 || $serviceFeeRate >= 1) {
            $serviceFeeRate = 0.03; // 异常保护
        }

        // 计算包含手续费的最终价格
        // 公式：最终价格 = (本金 + 增值) / (1 - 手续费率)
        // 这样卖家收到的全款(最终价格) - 支付的手续费(最终价格*费率) = 本金 + 增值
        if ($serviceFeeRate >= 0.99) {
             // 极端情况保护，避免除零或过大
             $newPrice = $targetNet;
        } else {
             $newPrice = $targetNet / (1 - $serviceFeeRate);
        }

        // 保留2位小数
        $newPrice = round($newPrice, 2);

        // 更新商品价格和价格分区
        // 查找匹配的分区配置
        $zone = Db::name('price_zone_config')
            ->where('status', '1')
            ->where('min_price', '<=', $newPrice)
            ->where('max_price', '>=', $newPrice)
            ->find();
            
        $newPriceZone = $zone ? $zone['name'] : '';
        $newZoneId = $zone ? (int)$zone['id'] : 0;
        
        // 如果没有匹配的分区（例如价格超过最大分区上限），尝试查找包含该价格的“最高分区”
        if (!$zone) {
            $maxZone = Db::name('price_zone_config')
                ->where('status', '1')
                ->order('max_price', 'desc')
                ->find();
            // 如果价格高于最高分区的上限，也可归入最高分区（或根据业务需求设为0）
            // 这里假设如果不匹配则不属于任何分区(0)或保持原样，建议设为0提示异常
        }

        Db::name('collection_item')
            ->where('id', $itemId)
            ->update([
                'price' => $newPrice,
                'price_zone' => $newPriceZone,
                'zone_id' => $newZoneId, // 关键：同步更新 zone_id
                'update_time' => time(),
            ]);
    }

    /**
     * 通过确权编号或MD5指纹查询藏品
     * 公开接口，无需登录
     * 用于防伪验证和溯源查询
     * 
     * @Apidoc\Title("通过确权编号或MD5查询藏品")
     * @Apidoc\Tag("藏品商城,防伪验证")
     * @Apidoc\Method("GET")
     * @Apidoc\Url("/api/collectionItem/queryByCode")
     * @Apidoc\Query(name="code", type="string", require=true, desc="确权编号或MD5指纹（精确查询）")
     */
    public function queryByCode(): void
    {
        // 获取查询参数
        $code = trim($this->request->param('code/s', ''));
        
        // 参数校验
        if (empty($code)) {
            $this->error('请输入确权编号或MD5指纹');
        }
        
        // 预处理查询值：支持带或不带 0x 前缀的 hash
        $codeWithPrefix = $code;
        $codeWithoutPrefix = $code;
        if (str_starts_with($code, '0x')) {
            $codeWithoutPrefix = substr($code, 2);
        } else {
            $codeWithPrefix = '0x' . $code;
        }
        
        // 查询藏品（支持多种格式）
        // 1. 确权编号（asset_code）：格式如 37-DATA-0001-0001 或 LEGACY-20260104-xxx
        // 2. MD5指纹（tx_hash）：格式如 0x1a2b3c4d... 或 1a2b3c4d...
        // 3. 确权哈希（rights_hash）：区块链确权后的哈希
        $item = Db::name('collection_item')
            ->where('status', '=', '1') // 只查询上架中的藏品
            ->where(function($query) use ($code, $codeWithPrefix, $codeWithoutPrefix) {
                $query->where('asset_code', '=', $code)           // 精确匹配确权编号
                      ->whereOr('tx_hash', '=', $code)            // 精确匹配 tx_hash（原值）
                      ->whereOr('tx_hash', '=', $codeWithPrefix)  // 匹配带 0x 前缀
                      ->whereOr('tx_hash', '=', $codeWithoutPrefix) // 匹配不带 0x 前缀
                      ->whereOr('rights_hash', '=', $code)        // 匹配 rights_hash
                      ->whereOr('rights_hash', '=', $codeWithPrefix)
                      ->whereOr('rights_hash', '=', $codeWithoutPrefix);
            })
            ->find();
        
        // 如果商品表未找到，尝试从用户藏品表查找（支持通过用户藏品关联查询）
        if (!$item) {
            $userCollection = Db::name('user_collection')
                ->alias('uc')
                ->join('collection_item ci', 'uc.item_id = ci.id', 'LEFT')
                ->where(function($query) use ($code, $codeWithPrefix, $codeWithoutPrefix) {
                    $query->where('ci.asset_code', '=', $code)
                          ->whereOr('ci.tx_hash', '=', $code)
                          ->whereOr('ci.tx_hash', '=', $codeWithPrefix)
                          ->whereOr('ci.tx_hash', '=', $codeWithoutPrefix)
                          ->whereOr('ci.rights_hash', '=', $code)
                          ->whereOr('uc.rights_hash', '=', $code); // 用户藏品表的确权哈希
                })
                ->field('ci.*')
                ->find();
            
            if ($userCollection) {
                $item = $userCollection;
            }
        }
        
        if (!$item) {
            $this->error('未找到匹配的藏品');
        }
        
        // 处理图片URL
        $item['image'] = $item['image'] ? full_url($item['image'], false) : '';
        
        // 格式化价格字段
        $item['price'] = (float)$item['price'];
        $item['issue_price'] = isset($item['issue_price']) ? (float)$item['issue_price'] : (float)$item['price'];
        
        // 将 tx_hash 作为 fingerprint 返回（统一字段名）
        $item['fingerprint'] = $item['tx_hash'] ?? '';
        
        // 查询持有人信息
        // 仅当藏品已交付给用户且未售出时返回持有人信息
        $holder = Db::name('user_collection')
            ->alias('uc')
            ->leftJoin('user u', 'uc.user_id = u.id')
            ->where('uc.item_id', $item['id'])
            ->where('uc.delivery_status', '=', 0) // delivery_status=0 表示未提货（即藏品在用户手中）
            ->where('uc.consignment_status', '<>', 2) // consignment_status != 2 表示未售出
            ->field('uc.user_id, u.username, u.nickname, u.mobile')
            ->order('uc.buy_time desc')
            ->find();
        
        if ($holder) {
            // 手机号脱敏：保留前3位和后4位
            $mobile = $holder['mobile'] ?? '';
            if (strlen($mobile) >= 11) {
                $holder['mobile'] = substr($mobile, 0, 3) . '****' . substr($mobile, -4);
            }
            $item['holder'] = $holder;
        } else {
            $item['holder'] = null;
        }
        
        // 返回结果
        $this->success('查询成功', $item);
    }

    /**
     * 格式化已售出寄售记录（瘦身字段模型）
     * 
     * @param array $record 从数据库查询的原始记录
     * @return array 格式化后的记录
     */
    protected function formatSoldConsignmentRecord(array $record): array
    {
        // [NEW] Hash 统一回退逻辑
        $realHash = $record['rights_hash'] ?? null;
        if (empty($realHash)) {
            $realHash = $record['hash'] ?? null;
        }
        if (empty($realHash)) {
            $realHash = md5(($record['user_collection_id'] ?? 0) . 'USER_COLLECTION_SALT_2025');
        }

        // A. 识别与展示（最小集）
        $result = [
            'consignment_id' => (int)($record['consignment_id'] ?? 0),
            'user_collection_id' => (int)($record['user_collection_id'] ?? 0),
            'title' => (string)($record['title'] ?? ''),
            'image' => toFullUrl($record['image'] ?? ''),
            'asset_code' => (string)($record['asset_code'] ?? ''),
            'hash' => $realHash,
            'session_id' => (int)($record['session_id'] ?? 0),
            'session_title' => (string)($record['session_title'] ?? ''),
            'zone_id' => (int)($record['zone_id'] ?? 0),
            'zone_name' => (string)($record['zone_name'] ?? ''),
            'consignment_status' => 2,
            'consignment_status_text' => '已售出',
        ];

        // B. 成交与结算快照（对账核心）
        $soldPrice = (float)($record['consignment_price'] ?? 0);
        $serviceFee = (float)($record['service_fee'] ?? 0);
        $serviceFeePaidAtApply = (int)($record['service_fee_paid_at_apply'] ?? 1) === 1;
        $settleStatus = (int)($record['settle_status'] ?? 0);
        $settleTime = (int)($record['settle_time'] ?? 0);
        $soldTime = (int)($record['sold_time'] ?? 0);
        $createTime = (int)($record['create_time'] ?? 0);

        $result['sold_price'] = round($soldPrice, 2);
        $result['service_fee'] = round($serviceFee, 2);
        $result['service_fee_paid_at_apply'] = $serviceFeePaidAtApply;
        $result['settle_status'] = $settleStatus;
        $result['settle_time'] = $settleTime > 0 ? $settleTime : null;
        $result['create_time'] = $createTime;
        $result['sold_time'] = $soldTime > 0 ? $soldTime : ($record['update_time'] ?? $createTime); // 兜底使用 update_time

        // C. 本金/利润与到账拆分
        $principalAmount = (float)($record['principal_amount'] ?? 0);
        $profitAmount = (float)($record['profit_amount'] ?? 0);
        
        $result['principal_amount'] = round($principalAmount, 2);
        $result['profit_amount'] = round($profitAmount, 2);
        
        // 到账拆分
        $result['payout_principal_withdrawable'] = round((float)($record['payout_principal_withdrawable'] ?? 0), 2);
        $result['payout_principal_consume'] = round((float)($record['payout_principal_consume'] ?? 0), 2);
        $result['payout_profit_withdrawable'] = round((float)($record['payout_profit_withdrawable'] ?? 0), 2);
        $result['payout_profit_consume'] = round((float)($record['payout_profit_consume'] ?? 0), 2);
        $result['payout_total_withdrawable'] = round((float)($record['payout_total_withdrawable'] ?? 0), 2);
        $result['payout_total_consume'] = round((float)($record['payout_total_consume'] ?? 0), 2);

        // D. 规则标识
        $result['settle_rule'] = (string)($record['settle_rule'] ?? 'normal');
        $result['is_legacy_snapshot'] = (int)($record['is_legacy_snapshot'] ?? 0);
        $result['legacy_unlock_price_snapshot'] = round((float)($record['legacy_unlock_price_snapshot'] ?? 0), 2);

        // 如果快照字段为空，尝试从现有字段计算（兼容旧数据）
        if ($result['principal_amount'] == 0 && isset($record['original_price'])) {
            $result['principal_amount'] = round((float)$record['original_price'], 2);
        }
        if ($result['sold_price'] == 0 && isset($record['consignment_price'])) {
            $result['sold_price'] = round((float)$record['consignment_price'], 2);
        }

        // 购入价格字段优先级处理
        $buyPrice = null;
        if (isset($record['original_buy_price']) && (float)$record['original_buy_price'] > 0) {
            $buyPrice = $record['original_buy_price']; // 优先使用原始购入价格
        } elseif (isset($record['buy_price'])) {
            $buyPrice = $record['buy_price'];
        } elseif (isset($record['principal_amount']) && (float)$record['principal_amount'] > 0) {
            $buyPrice = $record['principal_amount'];
        } elseif (isset($record['price'])) {
            $buyPrice = $record['price'];
        } elseif (isset($record['original_price'])) {
            $buyPrice = $record['original_price'];
        } elseif (isset($record['original_record'])) {
            $originalRecord = $record['original_record'];
            if (isset($originalRecord['buy_price'])) {
                $buyPrice = $originalRecord['buy_price'];
            } elseif (isset($originalRecord['principal_amount'])) {
                $buyPrice = $originalRecord['principal_amount'];
            } elseif (isset($originalRecord['price'])) {
                $buyPrice = $originalRecord['price'];
            }
        }

        // 强制设置 buy_price：优先使用 original_buy_price，否则使用 original_price 作为兜底
        if (isset($record['original_buy_price']) && (float)$record['original_buy_price'] > 0) {
            $result['buy_price'] = (float)$record['original_buy_price'];
        } elseif (isset($record['original_price']) && (float)$record['original_price'] > 0) {
            $result['buy_price'] = (float)$record['original_price'];
        } elseif ($buyPrice !== null && (float)$buyPrice > 0) {
            $result['buy_price'] = is_numeric($buyPrice) ? (float)$buyPrice : (string)$buyPrice;
        }

        return $result;
    }

    #[
        Apidoc\Title("手动转为矿机"),
        Apidoc\Tag("藏品商城,矿机"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/collectionItem/toMining"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "user_collection_id", type: "int", require: true, desc: "用户藏品记录ID"),
    ]
    public function toMining(): void
    {
        if (!$this->auth->isLogin()) {
             $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;
        $userCollectionId = $this->request->param('user_collection_id/d', 0);

        if (!$userCollectionId) {
            $this->error('参数错误');
        }

        Db::startTrans();
        try {
            // 1. 查询藏品并锁定
            $collection = Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();

            if (!$collection) {
                Db::rollback();
                $this->error('藏品不存在或无权操作');
            }

            // 2. 检查状态
            // 已经是矿机
            if (isset($collection['mining_status']) && $collection['mining_status'] == 1) {
                Db::rollback();
                $this->error('该藏品已经是矿机状态，无需重复操作');
            }
            // 已提货
            if ($collection['delivery_status'] == 1) {
                Db::rollback();
                $this->error('该藏品已提货，无法转为矿机');
            }
            // 已售出
            if ($collection['consignment_status'] == 2) {
                 Db::rollback();
                 $this->error('该藏品已售出，无法转为矿机');
            }

            // 3. 执行转矿机操作
            $now = time();
            
            // 🔧 清理所有非已售出的寄售记录（避免重复记录）
            // 清理 status = 0(已取消), 1(寄售中), 3(已下架/流拍) 的记录
            // 保留 status = 2(已售出) 的历史记录
            Db::name('collection_consignment')
                ->where('user_collection_id', $collection['id'])
                ->whereIn('status', [0, 1, 3]) // 清理已取消、寄售中、已下架的记录
                ->update([
                    'status' => 0, // 统一标记为已取消
                    'update_time' => $now
                ]);

            // 更新用户藏品表
            Db::name('user_collection')
                ->where('id', $collection['id'])
                ->update([
                    'mining_status' => 1,
                    'mining_start_time' => $now,
                    'last_dividend_time' => 0, // 等待第一次分红
                    'consignment_status' => 0, // 确保寄售状态归零
                    'update_time' => $now
                ]);

            // 如果商品已上架，下架（转为矿机后不再在商城展示）
            $item = Db::name('collection_item')
                ->where('id', $collection['item_id'])
                ->find();
            
            if ($item && isset($item['status']) && $item['status'] == '1') {
                Db::name('collection_item')
                    ->where('id', $item['id'])
                    ->update([
                        'status' => '0',
                        'update_time' => $now,
                    ]);
            }

            // 记录日志
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'manual_mining',
                'change_field' => 'mining_status',
                'change_value' => '1',
                'before_value' => '0',
                'after_value' => '1',
                'remark' => "用户手动将藏品转为矿机",
                'extra' => json_encode([
                    'user_collection_id' => $collection['id'],
                    'item_id' => $collection['item_id'],
                    'title' => $collection['title'] ?? '',
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
                'update_time' => $now,
            ]);

            Db::commit();
            $this->success('操作成功，藏品已转为矿机');

        } catch (\think\exception\HttpResponseException $e) {
            Db::rollback();
            throw $e;
        } catch (\Throwable $e) {
            Db::rollback();
             \think\facade\Log::error('手动转矿机失败: ' . $e->getMessage());
            $this->error('操作失败: ' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("订单详情"),
        Apidoc\Tag("藏品商城,订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionItem/orderDetail"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "id", type: "int", require: false, desc: "订单ID"),
        Apidoc\Query(name: "order_no", type: "string", require: false, desc: "订单号"),
        Apidoc\Returned("id", type: "int", desc: "订单ID"),
        Apidoc\Returned("order_no", type: "string", desc: "订单号"),
        Apidoc\Returned("user_id", type: "int", desc: "用户ID"),
        Apidoc\Returned("total_amount", type: "float", desc: "订单金额"),
        Apidoc\Returned("pay_type", type: "string", desc: "支付方式"),
        Apidoc\Returned("pay_type_text", type: "string", desc: "支付方式文本"),
        Apidoc\Returned("status", type: "string", desc: "订单状态"),
        Apidoc\Returned("status_text", type: "string", desc: "订单状态文本"),
        Apidoc\Returned("pay_time", type: "int", desc: "支付时间戳"),
        Apidoc\Returned("pay_time_text", type: "string", desc: "支付时间"),
        Apidoc\Returned("complete_time", type: "int", desc: "完成时间戳"),
        Apidoc\Returned("complete_time_text", type: "string", desc: "完成时间"),
        Apidoc\Returned("create_time", type: "int", desc: "创建时间戳"),
        Apidoc\Returned("create_time_text", type: "string", desc: "创建时间"),
        Apidoc\Returned("remark", type: "string", desc: "备注"),
        Apidoc\Returned("items", type: "array", desc: "订单明细列表"),
        Apidoc\Returned("items[].id", type: "int", desc: "明细ID"),
        Apidoc\Returned("items[].item_id", type: "int", desc: "藏品ID"),
        Apidoc\Returned("items[].item_title", type: "string", desc: "藏品标题"),
        Apidoc\Returned("items[].item_image", type: "string", desc: "藏品图片"),
        Apidoc\Returned("items[].price", type: "float", desc: "单价"),
        Apidoc\Returned("items[].quantity", type: "int", desc: "数量"),
        Apidoc\Returned("items[].subtotal", type: "float", desc: "小计"),
    ]
    public function orderDetail(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $id = $this->request->param('id/d', 0);
        $orderNo = $this->request->param('order_no', '');

        if (!$id && !$orderNo) {
            $this->error('请提供订单ID或订单号');
        }

        $where = [
            ['user_id', '=', $this->auth->id]
        ];

        if ($id > 0) {
            $where[] = ['id', '=', $id];
        } else {
            $where[] = ['order_no', '=', $orderNo];
        }

        $order = Db::name('collection_order')
            ->where($where)
            ->find();

        if (!$order) {
            $this->error('订单不存在');
        }

        // 查询订单明细
        $orderItems = Db::name('collection_order_item')
            ->where('order_id', $order['id'])
            ->select()
            ->toArray();

        // 处理图片URL
        foreach ($orderItems as &$item) {
            $item['item_image'] = $item['item_image'] ? full_url($item['item_image'], false) : '';
            $item['price'] = (float)$item['price'];
            $item['subtotal'] = (float)$item['subtotal'];
        }

        // 支付方式文本
        $payTypeMap = [
            'money' => '余额支付',
            'score' => '消费金支付',
        ];
        $order['pay_type_text'] = $payTypeMap[$order['pay_type']] ?? $order['pay_type'];

        // 订单状态文本
        $statusMap = [
            'pending' => '待支付',
            'paid' => '已支付',
            'completed' => '已完成',
            'cancelled' => '已取消',
            'refunded' => '已退款',
        ];
        $order['status_text'] = $statusMap[$order['status']] ?? $order['status'];

        // 时间格式化
        $order['pay_time_text'] = $order['pay_time'] ? date('Y-m-d H:i:s', $order['pay_time']) : '';
        $order['complete_time_text'] = $order['complete_time'] ? date('Y-m-d H:i:s', $order['complete_time']) : '';
        $order['create_time_text'] = date('Y-m-d H:i:s', $order['create_time']);

        // 格式化金额
        $order['total_amount'] = (float)$order['total_amount'];

        $order['items'] = $orderItems;

        $this->success('', $order);
    }
}

