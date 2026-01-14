<?php

namespace app\admin\controller\finance;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\CompanyPaymentAccount as CompanyPaymentAccountModel;
use think\facade\Db;

class CompanyPaymentAccount extends Backend
{
    /**
     * @var CompanyPaymentAccountModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['account_name', 'account_number', 'bank_name'];

    protected string|array $defaultSortField = 'sort desc,id desc';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new CompanyPaymentAccountModel();
    }

    /**
     * 查看
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        [$where, $alias, $limit, $order] = $this->queryBuilder();

        $res = $this->model
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        $list = $res->items();
        $statusMap = CompanyPaymentAccountModel::getStatusMap();
        foreach ($list as &$item) {
            // 账户类型文本
            $typeMap = [
                'bank_card' => '银行卡',
                'alipay' => '支付宝',
                'wechat' => '微信',
                'usdt' => 'USDT',
                'digital_rmb' => '数字人民币',
                'unionpay' => '银联快捷',
            ];
            $item->type_text = $typeMap[$item->type] ?? '未知';

            // 设置默认支付图标
            if (empty($item->icon)) {
                $defaultIcons = [
                    'bank_card' => '/static/images/payment/bank_card.png',
                    'alipay' => '/static/images/payment/alipay.png',
                    'wechat' => '/static/images/payment/wechat.png',
                    'usdt' => '/static/images/payment/usdt.png',
                    'digital_rmb' => '/static/images/payment/digital_rmb.png',
                    'unionpay' => '/static/images/payment/unionpay.png',
                ];
                $item->icon = $defaultIcons[$item->type] ?? '';
            }

            $item->status_text = $statusMap[(int)$item->status] ?? '未知';
            
            // 确保所有字符串字段都是有效的 UTF-8
            $stringFields = ['account_name', 'bank_name', 'bank_branch', 'remark', 'type_text', 'account_number'];
            foreach ($stringFields as $field) {
                if (isset($item->$field) && is_string($item->$field)) {
                    if (!mb_check_encoding($item->$field, 'UTF-8')) {
                        $item->$field = mb_convert_encoding($item->$field, 'UTF-8', 'UTF-8,GBK,GB2312,BIG5');
                        // 如果转换后仍然无效，清理无效字符
                        if (!mb_check_encoding($item->$field, 'UTF-8')) {
                            $item->$field = mb_convert_encoding($item->$field, 'UTF-8', 'UTF-8');
                        }
                    }
                }
            }
        }

        $this->success('', [
            'list' => $list,
            'total' => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 添加
     * @throws Throwable
     */
    public function add(): void
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            // 验证必填字段
            if (empty($data['type'])) {
                $this->error('账户类型不能为空');
            }
            if (!in_array($data['type'], ['bank_card', 'alipay', 'wechat', 'usdt', 'digital_rmb', 'unionpay'])) {
                $this->error('账户类型不正确');
            }
            if (empty($data['account_name'])) {
                $this->error('账户名不能为空');
            }
            if (empty($data['account_number'])) {
                $this->error('账号/卡号/地址不能为空');
            }
            if (!array_key_exists((int)($data['status'] ?? CompanyPaymentAccountModel::STATUS_RECHARGE), CompanyPaymentAccountModel::getStatusMap())) {
                $this->error('状态取值不正确');
            }
            if (!isset($data['status'])) {
                $data['status'] = CompanyPaymentAccountModel::STATUS_RECHARGE;
            }

            // 银行卡需要银行名称
            if ($data['type'] == 'bank_card' && empty($data['bank_name'])) {
                $this->error('银行名称不能为空');
            }

            // USDT需要网络类型
            if ($data['type'] == 'usdt') {
                if (empty($data['bank_branch'])) {
                    $this->error('USDT网络类型不能为空');
                }
                if (!in_array(strtoupper($data['bank_branch']), ['TRC20', 'ERC20', 'BEP20', 'OMNI'])) {
                    $this->error('USDT网络类型不正确，支持：TRC20、ERC20、BEP20、OMNI');
                }
                $data['bank_branch'] = strtoupper($data['bank_branch']);
            }

            // 直接存储明文，不加密
            $result = false;
            Db::startTrans();
            try {
                // 模型验证
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate && !$validate->scene('add')->check($data)) {
                            $this->error($validate->getError());
                        } elseif (!$this->modelSceneValidate && !$validate->check($data)) {
                            $this->error($validate->getError());
                        }
                    }
                }
                $result = $this->model->save($data);
                Db::commit();
            } catch (Throwable $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
            if ($result) {
                $this->success(__('Added successfully'));
            } else {
                $this->error(__('No rows were added'));
            }
        }

        $this->success();
    }

    /**
     * 编辑
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($this->request->isPost()) {
            $data = $this->request->post();
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            // 验证必填字段
            if (isset($data['type']) && !in_array($data['type'], ['bank_card', 'alipay', 'wechat', 'usdt', 'digital_rmb', 'unionpay'])) {
                $this->error('账户类型不正确');
            }
            if (isset($data['status']) && !array_key_exists((int)$data['status'], CompanyPaymentAccountModel::getStatusMap())) {
                $this->error('状态取值不正确');
            }

            // 直接存储明文，不加密

            // USDT网络类型转大写
            if (isset($data['bank_branch']) && $row->type == 'usdt') {
                $data['bank_branch'] = strtoupper($data['bank_branch']);
            }

            $result = false;
            Db::startTrans();
            try {
                // 模型验证
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate && !$validate->scene('edit')->check($data)) {
                            $this->error($validate->getError());
                        } elseif (!$this->modelSceneValidate && !$validate->check($data)) {
                            $this->error($validate->getError());
                        }
                    }
                }
                $result = $row->save($data);
                Db::commit();
            } catch (Throwable $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
            if ($result) {
                $this->success(__('Updated successfully'));
            } else {
                $this->error(__('No rows were updated'));
            }
        }

        // 直接使用明文，无需解密
        
        // 确保所有字符串字段都是有效的 UTF-8
        $stringFields = ['account_name', 'bank_name', 'bank_branch', 'remark', 'account_number'];
        foreach ($stringFields as $field) {
            if (isset($row->$field) && is_string($row->$field)) {
                if (!mb_check_encoding($row->$field, 'UTF-8')) {
                    $row->$field = mb_convert_encoding($row->$field, 'UTF-8', 'UTF-8,GBK,GB2312,BIG5');
                    if (!mb_check_encoding($row->$field, 'UTF-8')) {
                        $row->$field = mb_convert_encoding($row->$field, 'UTF-8', 'UTF-8');
                    }
                }
            }
        }

        $this->success('', [
            'row' => $row
        ]);
    }
}

