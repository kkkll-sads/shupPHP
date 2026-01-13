<?php

namespace app\api\controller;

use think\facade\Db;
use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("商城商品管理")]
class ShopProduct extends Frontend
{
    protected array $noNeedLogin = ['index', 'detail', 'categories', 'sales', 'latest'];

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * 从URL中提取路径部分，去除协议和域名
     */
    private function extractPath(string $url): string
    {
        if (!$url) {
            return '';
        }

        // 如果已经是相对路径（不以//或http开头），直接返回
        if (!preg_match('/^(\/\/|https?:\/\/)/i', $url)) {
            return $url;
        }

        // 从完整URL中提取路径部分
        $parsed = parse_url($url);
        return $parsed['path'] ?? '';
    }

    #[
        Apidoc\Title("商城商品列表"),
        Apidoc\Tag("商城,商品列表"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopProduct/index"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "10"),
        Apidoc\Query(name: "category", type: "string", require: false, desc: "商品分类"),
        Apidoc\Query(name: "purchase_type", type: "string", require: false, desc: "购买方式: money/score/both"),
        Apidoc\Returned("list", type: "array", desc: "商品列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "商品ID"),
        Apidoc\Returned("list[].name", type: "string", desc: "商品名称"),
        Apidoc\Returned("list[].thumbnail", type: "string", desc: "商品缩略图路径"),
        Apidoc\Returned("list[].category", type: "string", desc: "商品分类"),
        Apidoc\Returned("list[].price", type: "float", desc: "商品价格（余额）"),
        Apidoc\Returned("list[].green_power_amount", type: "float", desc: "消费金支付金额"),
        Apidoc\Returned("list[].balance_available_amount", type: "float", desc: "可用金额支付金额"),
        Apidoc\Returned("list[].score_price", type: "int", desc: "积分价格"),
        Apidoc\Returned("list[].stock", type: "int", desc: "库存数量"),
        Apidoc\Returned("list[].sales", type: "int", desc: "销量"),
        Apidoc\Returned("list[].purchase_type", type: "string", desc: "购买方式"),
        Apidoc\Returned("list[].is_physical", type: "string", desc: "是否实物商品"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
        Apidoc\Returned("page", type: "int", desc: "当前页码"),
        Apidoc\Returned("limit", type: "int", desc: "每页数量"),
    ]
    public function index(): void
    {
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $category = $this->request->param('category', '');
        $purchaseType = $this->request->param('purchase_type', '');

        $limit = min($limit, 50); // 最大50条

        $where = [['status', '=', '1']];
        if ($category) {
            $where[] = ['category', '=', $category];
        }
        if ($purchaseType) {
            $where[] = ['purchase_type', 'in', [$purchaseType, 'both']];
        }

        $list = Db::name('shop_product')
            ->where($where)
            ->field([
                'id',
                'name',
                'thumbnail',
                'category',
                'price',
                'green_power_ratio',
                'balance_available_ratio',
                'score_price',
                'stock',
                'sales',
                'purchase_type',
                'is_physical',
            ])
            ->order('sort desc, id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 处理图片路径和价格计算
        foreach ($list as &$item) {
            $item['thumbnail'] = $this->extractPath($item['thumbnail']);
            $item['price'] = (float)$item['price'];

            // 计算消费金和可用金额的具体金额
            $item['green_power_amount'] = round($item['price'] * ($item['green_power_ratio'] / 100), 2);
            $item['balance_available_amount'] = round($item['price'] * ($item['balance_available_ratio'] / 100), 2);

            // 移除比例字段，只返回具体金额
            unset($item['green_power_ratio'], $item['balance_available_ratio']);
        }

        $total = Db::name('shop_product')
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
        Apidoc\Title("商城商品详情"),
        Apidoc\Tag("商城,商品详情"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopProduct/detail"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "商品ID"),
        Apidoc\Returned("id", type: "int", desc: "商品ID"),
        Apidoc\Returned("name", type: "string", desc: "商品名称"),
        Apidoc\Returned("thumbnail", type: "string", desc: "商品缩略图路径"),
        Apidoc\Returned("images", type: "array", desc: "商品图片路径列表"),
        Apidoc\Returned("description", type: "string", desc: "商品详细描述"),
        Apidoc\Returned("category", type: "string", desc: "商品分类"),
        Apidoc\Returned("price", type: "float", desc: "商品价格（余额）"),
        Apidoc\Returned("green_power_amount", type: "float", desc: "消费金支付金额"),
        Apidoc\Returned("balance_available_amount", type: "float", desc: "可用金额支付金额"),
        Apidoc\Returned("score_price", type: "int", desc: "积分价格"),
        Apidoc\Returned("stock", type: "int", desc: "库存数量"),
        Apidoc\Returned("sales", type: "int", desc: "销量"),
        Apidoc\Returned("purchase_type", type: "string", desc: "购买方式: money/score/both"),
        Apidoc\Returned("is_physical", type: "string", desc: "是否实物商品: 0=虚拟，1=实物"),
    ]
    public function detail(): void
    {
        $id = $this->request->param('id/d', 0);
        
        if (!$id) {
            $this->error('参数错误');
        }

        $detail = Db::name('shop_product')
            ->where('id', $id)
            ->where('status', '1')
            ->find();

        if (!$detail) {
            $this->error('商品不存在或已下架');
        }

        // 处理图片路径
        $detail['thumbnail'] = $this->extractPath($detail['thumbnail']);

        // 处理多图
        if ($detail['images']) {
            $images = explode(',', $detail['images']);
            $detail['images'] = array_map(function($img) {
                return $this->extractPath($img);
            }, $images);
        } else {
            $detail['images'] = [];
        }

        $detail['price'] = (float)$detail['price'];

        // 计算消费金和可用金额的具体金额
        $detail['green_power_amount'] = round($detail['price'] * ($detail['green_power_ratio'] / 100), 2);
        $detail['balance_available_amount'] = round($detail['price'] * ($detail['balance_available_ratio'] / 100), 2);

        // 移除比例字段，只返回具体金额
        unset($detail['green_power_ratio'], $detail['balance_available_ratio']);

        $this->success('', $detail);
    }

    #[
        Apidoc\Title("商品分类列表"),
        Apidoc\Tag("商城,分类"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopProduct/categories"),
        Apidoc\Returned("list", type: "array", desc: "分类列表"),
    ]
    public function categories(): void
    {
        $categories = Db::name('shop_product')
            ->where('status', '1')
            ->where('category', '<>', '')
            ->distinct(true)
            ->column('category');

        $this->success('', [
            'list' => array_values($categories),
        ]);
    }

    #[
        Apidoc\Title("热销商品列表（按销量排序）"),
        Apidoc\Tag("商城,热销商品"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopProduct/sales"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "10"),
        Apidoc\Query(name: "category", type: "string", require: false, desc: "商品分类"),
        Apidoc\Returned("list", type: "array", desc: "商品列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "商品ID"),
        Apidoc\Returned("list[].name", type: "string", desc: "商品名称"),
        Apidoc\Returned("list[].thumbnail", type: "string", desc: "商品缩略图路径"),
        Apidoc\Returned("list[].category", type: "string", desc: "商品分类"),
        Apidoc\Returned("list[].price", type: "float", desc: "商品价格（余额）"),
        Apidoc\Returned("list[].green_power_amount", type: "float", desc: "消费金支付金额"),
        Apidoc\Returned("list[].balance_available_amount", type: "float", desc: "可用金额支付金额"),
        Apidoc\Returned("list[].score_price", type: "int", desc: "积分价格"),
        Apidoc\Returned("list[].stock", type: "int", desc: "库存数量"),
        Apidoc\Returned("list[].sales", type: "int", desc: "销量"),
        Apidoc\Returned("list[].purchase_type", type: "string", desc: "购买方式"),
        Apidoc\Returned("list[].is_physical", type: "string", desc: "是否实物商品"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
        Apidoc\Returned("page", type: "int", desc: "当前页码"),
        Apidoc\Returned("limit", type: "int", desc: "每页数量"),
    ]
    public function sales(): void
    {
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $category = $this->request->param('category', '');

        $limit = min($limit, 50); // 最大50条

        $where = [['status', '=', '1']];
        if ($category) {
            $where[] = ['category', '=', $category];
        }

        $list = Db::name('shop_product')
            ->where($where)
            ->field([
                'id',
                'name',
                'thumbnail',
                'category',
                'price',
                'green_power_ratio',
                'balance_available_ratio',
                'score_price',
                'stock',
                'sales',
                'purchase_type',
                'is_physical',
            ])
            ->order('sales desc, id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 处理图片路径
        foreach ($list as &$item) {
            $item['thumbnail'] = $this->extractPath($item['thumbnail']);
            $item['price'] = (float)$item['price'];
        }

        $total = Db::name('shop_product')
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
        Apidoc\Title("最新商品列表"),
        Apidoc\Tag("商城,最新商品"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopProduct/latest"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "10"),
        Apidoc\Query(name: "category", type: "string", require: false, desc: "商品分类"),
        Apidoc\Returned("list", type: "array", desc: "商品列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "商品ID"),
        Apidoc\Returned("list[].name", type: "string", desc: "商品名称"),
        Apidoc\Returned("list[].thumbnail", type: "string", desc: "商品缩略图路径"),
        Apidoc\Returned("list[].category", type: "string", desc: "商品分类"),
        Apidoc\Returned("list[].price", type: "float", desc: "商品价格（余额）"),
        Apidoc\Returned("list[].green_power_amount", type: "float", desc: "消费金支付金额"),
        Apidoc\Returned("list[].balance_available_amount", type: "float", desc: "可用金额支付金额"),
        Apidoc\Returned("list[].score_price", type: "int", desc: "积分价格"),
        Apidoc\Returned("list[].stock", type: "int", desc: "库存数量"),
        Apidoc\Returned("list[].sales", type: "int", desc: "销量"),
        Apidoc\Returned("list[].purchase_type", type: "string", desc: "购买方式"),
        Apidoc\Returned("list[].is_physical", type: "string", desc: "是否实物商品"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
        Apidoc\Returned("page", type: "int", desc: "当前页码"),
        Apidoc\Returned("limit", type: "int", desc: "每页数量"),
    ]
    public function latest(): void
    {
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $category = $this->request->param('category', '');

        $limit = min($limit, 50); // 最大50条

        $where = [['status', '=', '1']];
        if ($category) {
            $where[] = ['category', '=', $category];
        }

        $list = Db::name('shop_product')
            ->where($where)
            ->field([
                'id',
                'name',
                'thumbnail',
                'category',
                'price',
                'green_power_ratio',
                'balance_available_ratio',
                'score_price',
                'stock',
                'sales',
                'purchase_type',
                'is_physical',
            ])
            ->order('create_time desc, id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 处理图片路径
        foreach ($list as &$item) {
            $item['thumbnail'] = $this->extractPath($item['thumbnail']);
            $item['price'] = (float)$item['price'];
        }

        $total = Db::name('shop_product')
            ->where($where)
            ->count();

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }
}

