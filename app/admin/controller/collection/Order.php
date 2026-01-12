<?php

namespace app\admin\controller\collection;

use Throwable;
use think\facade\Db;
use app\common\controller\Backend;
use app\admin\model\CollectionOrder as CollectionOrderModel;
use app\common\model\UserActivityLog;
use think\exception\HttpResponseException;

class Order extends Backend
{
    /**
     * @var CollectionOrderModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['order_no', 'id'];

    protected array $withJoinTable = ['user'];

    protected string|array $defaultSortField = 'collection_order.id,desc';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new CollectionOrderModel();
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
            $allowFields = ['status', 'remark'];
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

        $this->success('', [
            'row' => $row,
            'remark' => get_route_remark(),
        ]);
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

        if ($order->status != 'paid') {
            $this->error('只有已支付的订单才能完成');
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
                    'memo' => '取消藏品订单退款（退回可用余额），订单号：' . $order->order_no,
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
                    'after_value' => (string)$afterMoney,
                    'remark' => '取消藏品订单退款，订单号：' . $order->order_no,
                    'extra' => json_encode([
                        'order_id' => $order->id,
                        'order_no' => $order->order_no,
                        'refund_amount' => (string)$order->total_amount,
                        'refund_type' => 'money',
                        'operation' => 'collection_order_cancel',
                        'order_type' => 'collection',
                    ], JSON_UNESCAPED_UNICODE),
                ]);

                // 恢复藏品库存
                $items = Db::name('collection_order_item')->where('order_id', $order->id)->select();
                foreach ($items as $item) {
                    // 获取当前藏品信息
                    $collectionItem = Db::name('collection_item')
                        ->where('id', $item['item_id'])
                        ->field('sales, stock')
                        ->find();
                    
                    if ($collectionItem) {
                        // 增加库存
                        $newStock = $collectionItem['stock'] + $item['quantity'];
                        // 减少销量，确保不会小于0
                        $newSales = max(0, $collectionItem['sales'] - $item['quantity']);
                        
                        Db::name('collection_item')
                            ->where('id', $item['item_id'])
                            ->update([
                                'stock' => $newStock,
                                'sales' => $newSales,
                            ]);
                    }
                }
            }

            // 更新订单状态
            Db::name('collection_order')->where('id', $order->id)->update(['status' => 'cancelled']);

            Db::commit();
            $this->success('取消订单成功');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
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

