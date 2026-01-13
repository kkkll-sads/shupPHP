<?php

namespace app\admin\controller\shop;

use Throwable;
use think\facade\Db;
use app\common\controller\Backend;
use app\admin\model\ShopOrder as ShopOrderModel;
use app\common\model\UserActivityLog;
use think\exception\HttpResponseException;

class Order extends Backend
{
    /**
     * @var ShopOrderModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['order_no', 'id', 'recipient_name', 'recipient_phone'];

    protected array $withJoinTable = ['user'];

    protected string|array $defaultSortField = 'shop_order.id,desc';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new ShopOrderModel();
    }

    /**
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        [$where, $alias, $limit, $order] = $this->queryBuilder('id desc');

        $res = $this->model
            ->alias($alias)
            ->where($where)
            ->with(['user', 'items'])
            ->order($order)
            ->paginate($limit);

        $list = $res->items();
        foreach ($list as &$item) {
            $item->status_text = $item->status_text_attr;
            $item->pay_type_text = $item->pay_type_text_attr;
            
            // 判断订单的商品类型
            $hasPhysical = false;
            $hasVirtual = false;
            $hasCardProduct = false;
            
            // 为订单明细添加商品类型信息
            if ($item->items) {
                foreach ($item->items as $orderItem) {
                    $product = Db::name('shop_product')
                        ->where('id', $orderItem->product_id)
                        ->field('is_physical, is_card_product')
                        ->find();
                    if ($product) {
                        $orderItem->is_physical = $product['is_physical'];
                        $orderItem->is_card_product = $product['is_card_product'] ?? '0';
                        
                        if ($product['is_physical'] == '1') {
                            $hasPhysical = true;
                        } else {
                            $hasVirtual = true;
                            if (isset($product['is_card_product']) && $product['is_card_product'] == '1') {
                                $hasCardProduct = true;
                            }
                        }
                    }
                }
            }
            
            // 设置订单的商品类型标识
            if ($hasPhysical && $hasVirtual) {
                $item->product_type = 'mixed'; // 混合订单
            } elseif ($hasPhysical) {
                $item->product_type = 'physical'; // 实物商品
            } elseif ($hasCardProduct) {
                $item->product_type = 'card'; // 卡密商品
            } else {
                $item->product_type = 'virtual'; // 虚拟商品
            }
        }

        $this->success('', [
            'list' => $list,
            'total' => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->with(['user', 'items'])->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($this->request->isPost()) {
            $data = $this->excludeFields($this->request->post());
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            // 只允许修改特定字段
            $allowFields = ['status', 'shipping_no', 'shipping_company', 'admin_remark'];
            $updateData = [];
            foreach ($allowFields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }

            $result = false;
            $this->model->startTrans();
            try {
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) {
                            $validate->scene('edit');
                        }
                        $validate->check($updateData);
                    }
                }

                // 检查是否包含卡密商品，并且填写了备注
                $hasCardProduct = false;
                if ($row->items) {
                    foreach ($row->items as $item) {
                        $product = Db::name('shop_product')
                            ->where('id', $item->product_id)
                            ->field('is_card_product')
                            ->find();
                        if ($product && $product['is_card_product'] == '1') {
                            $hasCardProduct = true;
                            break;
                        }
                    }
                }

                // 如果是卡密商品订单，填写了备注，且当前状态是已支付，自动更新为已发货
                if ($hasCardProduct && 
                    isset($updateData['admin_remark']) && 
                    !empty($updateData['admin_remark']) && 
                    $row->status == 'paid') {
                    $updateData['status'] = 'shipped';
                    $updateData['ship_time'] = time();
                }

                // 如果状态改为已发货，记录发货时间
                if (isset($updateData['status']) && $updateData['status'] == 'shipped' && $row->status != 'shipped') {
                    $updateData['ship_time'] = time();
                }

                // 如果状态改为已完成，记录完成时间
                if (isset($updateData['status']) && $updateData['status'] == 'completed' && $row->status != 'completed') {
                    $updateData['complete_time'] = time();
                }

                $result = $row->save($updateData);
                $this->model->commit();
            } catch (HttpResponseException $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->model->rollback();
                $this->error($e->getMessage());
            }

            if ($result !== false) {
                $this->success(__('Updated successfully'));
            }
            $this->error(__('No rows were updated'));
        }

        $row->status_text = $row->status_text_attr;
        $row->pay_type_text = $row->pay_type_text_attr;

        // 判断订单的商品类型
        $hasPhysical = false;
        $hasVirtual = false;
        $hasCardProduct = false;

        // 为订单明细添加商品类型和卡密信息
        if ($row->items) {
            foreach ($row->items as $item) {
                $product = Db::name('shop_product')
                    ->where('id', $item->product_id)
                    ->field('is_physical, is_card_product')
                    ->find();
                if ($product) {
                    $item->is_physical = $product['is_physical'];
                    $item->is_card_product = $product['is_card_product'] ?? '0';
                    
                    if ($product['is_physical'] == '1') {
                        $hasPhysical = true;
                    } else {
                        $hasVirtual = true;
                        if (isset($product['is_card_product']) && $product['is_card_product'] == '1') {
                            $hasCardProduct = true;
                        }
                    }
                }
            }
        }

        // 设置订单的商品类型标识
        if ($hasPhysical && $hasVirtual) {
            $row->product_type = 'mixed'; // 混合订单
        } elseif ($hasPhysical) {
            $row->product_type = 'physical'; // 实物商品
        } elseif ($hasCardProduct) {
            $row->product_type = 'card'; // 卡密商品
        } else {
            $row->product_type = 'virtual'; // 虚拟商品
        }

        $this->success('', [
            'row' => $row,
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 发货
     * @throws Throwable
     */
    public function ship(): void
    {
        $id = $this->request->param('id/d', 0);
        $shippingNo = $this->request->param('shipping_no', '');
        $shippingCompany = $this->request->param('shipping_company', '');

        if (!$id) {
            $this->error('订单ID不能为空');
        }

        $order = $this->model->with('items')->find($id);
        if (!$order) {
            $this->error('订单不存在');
        }

        if ($order->status != 'paid') {
            $this->error('只有已支付的订单才能发货');
        }

        // 检查订单是否包含实物商品
        $hasPhysical = false;
        if ($order->items) {
            foreach ($order->items as $item) {
                $product = Db::name('shop_product')
                    ->where('id', $item->product_id)
                    ->field('is_physical')
                    ->find();
                if ($product && $product['is_physical'] == '1') {
                    $hasPhysical = true;
                    break;
                }
            }
        }

        if (!$hasPhysical) {
            $this->error('该订单为纯虚拟商品订单，无需发货');
        }

        $this->model->startTrans();
        try {
            if ($this->modelValidate) {
                $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                if (class_exists($validate)) {
                    $validate = new $validate();
                    $validate->scene('ship')->check([
                        'shipping_no' => $shippingNo,
                        'shipping_company' => $shippingCompany,
                    ]);
                }
            }

            $order->status = 'shipped';
            $order->shipping_no = $shippingNo;
            $order->shipping_company = $shippingCompany;
            $order->ship_time = time();
            $order->save();

            $this->model->commit();
            $this->success('发货成功');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }
    }

    /**
     * 完成订单
     * @throws Throwable
     */
    public function complete(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('订单ID不能为空');
        }

        $order = $this->model->find($id);
        if (!$order) {
            $this->error('订单不存在');
        }

        if ($order->status != 'shipped') {
            $this->error('只有已发货的订单才能完成');
        }

        $this->model->startTrans();
        try {
            $order->status = 'completed';
            $order->complete_time = time();
            $order->save();

            $this->model->commit();
            $this->success('订单已完成');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }
    }

    /**
     * 取消订单
     * @throws Throwable
     */
    public function cancel(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('订单ID不能为空');
        }

        $order = $this->model->find($id);
        if (!$order) {
            $this->error('订单不存在');
        }

        if (!in_array($order->status, ['pending', 'paid'])) {
            $this->error('该状态的订单不能取消');
        }

        Db::startTrans();
        try {
            // 如果已支付，需要退款
            if ($order->status == 'paid') {
                $user = Db::name('user')->where('id', $order->user_id)->lock(true)->find();
                if (!$user) {
                    throw new \Exception('用户不存在');
                }

                // 根据支付方式退款
                if ($order->pay_type == 'money') {
                    // 修复：退款统一退回可用余额（专项金）
                    $beforeBalance = (float)$user['balance_available'];
                    $afterBalance = round($beforeBalance + $order->total_amount, 2);
                    Db::name('user')->where('id', $order->user_id)->update([
                        'balance_available' => $afterBalance,
                        'update_time' => time(),
                    ]);

                    // 记录可用余额变动日志
                    Db::name('user_money_log')->insert([
                        'user_id' => $order->user_id,
                        'money' => $order->total_amount,
                        'before' => $beforeBalance,
                        'after' => $afterBalance,
                        'memo' => '取消订单退款（退回可用余额），订单号：' . $order->order_no,
                        'create_time' => time(),
                    ]);

                    // 记录用户活动日志
                    UserActivityLog::create([
                        'user_id' => $order->user_id,
                        'related_user_id' => 0,
                        'action_type' => 'balance',
                        'change_field' => 'balance_available',
                        'change_value' => (string)$order->total_amount,
                        'before_value' => (string)$beforeBalance,
                        'after_value' => (string)$afterBalance,
                        'remark' => '取消订单退款（退回可用余额），订单号：' . $order->order_no,
                        'extra' => [
                            'order_id' => $order->id,
                            'order_no' => $order->order_no,
                            'refund_amount' => (string)$order->total_amount,
                            'refund_type' => 'money',
                            'operation' => 'order_cancel',
                        ],
                    ]);
                } else {
                    // 退积分
                    $beforeScore = $user['score'];
                    $afterScore = $beforeScore + $order->total_score;
                    Db::name('user')->where('id', $order->user_id)->update(['score' => $afterScore]);

                    // 记录积分日志
                    Db::name('user_score_log')->insert([
                        'user_id' => $order->user_id,
                        'score' => $order->total_score,
                        'before' => $beforeScore,
                        'after' => $afterScore,
                        'memo' => '取消订单退积分，订单号：' . $order->order_no,
                        'create_time' => time(),
                    ]);

                    // 记录用户活动日志
                    UserActivityLog::create([
                        'user_id' => $order->user_id,
                        'related_user_id' => 0,
                        'action_type' => 'balance',
                        'change_field' => 'score',
                        'change_value' => (string)$order->total_score,
                        'before_value' => (string)$beforeScore,
                        'after_value' => (string)$afterScore,
                        'remark' => '取消订单退积分，订单号：' . $order->order_no,
                        'extra' => [
                            'order_id' => $order->id,
                            'order_no' => $order->order_no,
                            'refund_score' => (int)$order->total_score,
                            'refund_type' => 'score',
                            'operation' => 'order_cancel',
                        ],
                    ]);
                }

                // 恢复商品库存
                $items = Db::name('shop_order_item')->where('order_id', $order->id)->select();
                foreach ($items as $item) {
                    // 获取当前商品信息
                    $product = Db::name('shop_product')
                        ->where('id', $item['product_id'])
                        ->field('sales, stock')
                        ->find();
                    
                    if ($product) {
                        // 增加库存
                        $newStock = $product['stock'] + $item['quantity'];
                        // 减少销量，确保不会小于0
                        $newSales = max(0, $product['sales'] - $item['quantity']);
                        
                        Db::name('shop_product')
                            ->where('id', $item['product_id'])
                            ->update([
                                'stock' => $newStock,
                                'sales' => $newSales,
                            ]);
                }
            }
            }

            // 更新订单状态
            Db::name('shop_order')->where('id', $order->id)->update(['status' => 'cancelled']);

            Db::commit();
            $this->success('取消订单成功');
        } catch (HttpResponseException $e) {
            // 重新抛出HTTP响应异常（这是正常的响应流程）
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            // 记录详细错误日志
            trace($e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine(), 'error');
            trace($e->getTraceAsString(), 'error');
            $this->error($e->getMessage() ?: '取消订单失败，请稍后重试');
        }
    }

    /**
     * 不允许删除订单，只能取消
     */
    public function del(): void
    {
        $this->error('订单不能删除，只能取消');
    }
}

