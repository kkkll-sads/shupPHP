<?php

namespace app\api\controller;

use Throwable;
use RuntimeException;
use think\facade\Db;
use app\common\controller\Frontend;

class Finance extends Frontend
{
    protected array $noNeedLogin = ['index', 'detail'];

    public function initialize(): void
    {
        parent::initialize();
    }

    public function index(): void
    {
        $list = Db::name('finance_product')
            ->where('status', '1')
            ->order('sort desc,id desc')
            ->select()
            ->toArray();

        $list = array_map(function ($item) {
            return $this->formatProduct($item);
        }, $list);

        $this->success('', [
            'list' => $list,
            'banner' => $this->getFinanceBanner(),
        ]);
    }

    public function detail(): void
    {
        $id = $this->request->get('id/d', 0);
        if ($id <= 0) {
            $this->error('参数错误');
        }

        $product = Db::name('finance_product')->find($id);
        if (!$product || $product['status'] !== '1') {
            $this->error('产品不存在或未上架');
        }

        $this->success('', [
            'product' => $this->formatProduct($product),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function purchase(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'), [], 401);
        }

        if (!$this->request->isPost()) {
            $this->error(__('Invalid request method'));
        }

        $productId = (int)$this->request->post('product_id', 0);
        $quantity = max(1, (int)$this->request->post('quantity', 1));

        if ($productId <= 0) {
            $this->error('请选择正确的理财产品');
        }

        $userId = (int)$this->auth->id;

        $result = Db::transaction(function () use ($productId, $quantity, $userId) {
            $product = Db::name('finance_product')->where('id', $productId)->lock(true)->find();
            if (!$product || $product['status'] !== '1') {
                throw new RuntimeException('理财产品不存在或已下架');
            }

            $total = (int)$product['total_amount'];
            $sold = (int)$product['sold_amount'];
            $remaining = $total > 0 ? max(0, $total - $sold) : null;

            if ($total > 0 && $remaining !== null && $quantity > $remaining) {
                throw new RuntimeException('库存不足');
            }

            $minPurchase = (int)$product['min_purchase'];
            $maxPurchase = (int)$product['max_purchase'];
            if ($quantity < $minPurchase) {
                throw new RuntimeException(sprintf('最小购买份数为 %d', $minPurchase));
            }
            if ($maxPurchase > 0 && $quantity > $maxPurchase) {
                throw new RuntimeException(sprintf('最大购买份数为 %d', $maxPurchase));
            }

            $unitPrice = (float)$product['price'];
            $amount = round($unitPrice * $quantity, 2);
            $yieldRate = (float)$product['yield_rate'];

            $orderNo = $this->generateOrderNo();
            $now = time();

            $orderId = Db::name('finance_order')->insertGetId([
                'order_no' => $orderNo,
                'user_id' => $userId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'yield_rate' => $yieldRate,
                'status' => 'completed',
                'remark' => '后台理财购买自动完成',
                'pay_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
            ]);

            Db::name('finance_product')
                ->where('id', $productId)
                ->inc('sold_amount', $quantity)
                ->update(['update_time' => $now]);

            return [
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'amount' => $amount,
            ];
        });

        $this->success('购买完成', $result);
    }

    protected function formatProduct(array $item): array
    {
        $item['thumbnail'] = $item['thumbnail'] ? full_url($item['thumbnail'], false) : null;

        $total = (int)$item['total_amount'];
        $sold = (int)$item['sold_amount'];
        $remaining = $total > 0 ? max(0, $total - $sold) : 0;
        $progress = $total > 0 ? round(($sold / max(1, $total)) * 100, 2) : 0;

        $item['price'] = (float)$item['price'];
        $item['yield_rate'] = (float)$item['yield_rate'];
        $item['total_amount'] = $total;
        $item['sold_amount'] = $sold;
        $item['remaining_amount'] = $total > 0 ? $remaining : 0;
        $item['progress'] = $progress;

        return $item;
    }

    protected function generateOrderNo(): string
    {
        return 'F' . date('YmdHis') . random_int(1000, 9999);
    }

    protected function getFinanceBanner(): ?string
    {
        $banner = get_sys_config('finance_banner');
        if (!$banner) {
            return null;
        }
        return full_url($banner, false);
    }
}


