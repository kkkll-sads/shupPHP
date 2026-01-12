<?php

namespace app\admin\controller\user;

use Throwable;
use app\common\controller\Backend;
use think\facade\Db;

class PaymentAccount extends Backend
{
    /**
     * 查看支付账户列表
     * @throws Throwable
     */
    public function index(): void
    {
        // 手动构建查询条件（因为不使用模型）
        $quickSearch = $this->request->get("quickSearch/s", '');
        $limit = $this->request->get("limit/d", 10);
        $search = $this->request->get("search/a", []);
        
        $where = [];
        
        // 只显示默认账户（无论是银行卡还是支付宝，每个用户只能有一个默认账户）
        $where[] = ['pa.is_default', '=', 1];
        
        // 快速搜索
        if ($quickSearch) {
            $searchValue = '%' . str_replace('%', '\%', $quickSearch) . '%';
            $where[] = function($query) use ($searchValue) {
                $query->where('pa.id', 'like', $searchValue)
                    ->whereOr('pa.account_name', 'like', $searchValue)
                    ->whereOr('u.username', 'like', $searchValue)
                    ->whereOr('u.nickname', 'like', $searchValue);
            };
        }
        
        // 通用搜索
        foreach ($search as $field) {
            if (!is_array($field) || !isset($field['operator']) || !isset($field['field']) || !isset($field['val'])) {
                continue;
            }
            
            $fieldName = $field['field'];
            // 处理关联表字段
            if (str_contains($fieldName, '.')) {
                // 已经是完整字段名
            } else {
                // 默认使用 pa 表别名
                if (in_array($fieldName, ['username', 'nickname', 'mobile'])) {
                    $fieldName = 'u.' . $fieldName;
                } else {
                    $fieldName = 'pa.' . $fieldName;
                }
            }
            
            $operator = $field['operator'];
            $val = $field['val'];
            
            switch ($operator) {
                case '=':
                case '<>':
                    $where[] = [$fieldName, $operator, (string)$val];
                    break;
                case 'LIKE':
                case 'NOT LIKE':
                    $where[] = [$fieldName, $operator, '%' . str_replace('%', '\%', $val) . '%'];
                    break;
                case 'IN':
                case 'NOT IN':
                    $where[] = [$fieldName, $operator, is_array($val) ? $val : explode(',', $val)];
                    break;
                case 'select':
                    $where[] = [$fieldName, '=', $val];
                    break;
            }
        }
        
        // 排序
        $orderField = $this->request->get("order/s", 'pa.id desc');
        if ($orderField) {
            $orderParts = explode(',', $orderField);
            $order = [];
            foreach ($orderParts as $part) {
                $part = trim($part);
                if (str_contains($part, ' ')) {
                    $parts = explode(' ', $part);
                    $order[$parts[0]] = $parts[1] ?? 'asc';
                } else {
                    $order[$part] = 'desc';
                }
            }
        } else {
            $order = ['pa.id' => 'desc'];
        }
        
        $res = Db::name('user_payment_account')
            ->alias('pa')
            ->leftJoin('ba_user u', 'pa.user_id = u.id')
            ->field('pa.*, u.username, u.nickname, u.mobile')
            ->where($where)
            ->order($order)
            ->paginate($limit);

        // 处理数据：解密账号并格式化显示
        $items = $res->items();
        foreach ($items as &$item) {
            // 解密账号/卡号（完整显示给管理员）
            try {
                $decrypted = base64_decode($item['account_number']);
                if ($decrypted !== false) {
                    $item['account_number_display'] = $decrypted;
                } else {
                    $item['account_number_display'] = '解密失败';
                }
            } catch (Throwable $e) {
                $item['account_number_display'] = '解密失败';
            }
            
            // 添加类型文本
            $typeMap = [
                'bank_card' => '银行卡',
                'alipay' => '支付宝',
                'wechat' => '微信',
                'usdt' => 'USDT',
            ];
            $accountTypeMap = [
                'personal' => '个人',
                'company' => '公司',
            ];
            $auditStatusMap = [
                0 => '待审核',
                1 => '已通过',
                2 => '已拒绝',
            ];
            
            $item['type_text'] = $typeMap[$item['type']] ?? '未知';
            $item['account_type_text'] = $accountTypeMap[$item['account_type']] ?? '未知';
            $item['audit_status_text'] = $auditStatusMap[$item['audit_status']] ?? '未知';
        }

        $this->success('', [
            'list'   => $items,
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 查看详情
     * @throws Throwable
     */
    public function read(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('ID不能为空');
        }

        $row = Db::name('user_payment_account')
            ->alias('pa')
            ->leftJoin('ba_user u', 'pa.user_id = u.id')
            ->field('pa.*, u.username, u.nickname, u.mobile')
            ->where('pa.id', $id)
            ->find();

        if (!$row) {
            $this->error(__('Record not found'));
        }

        // 解密账号/卡号
        try {
            $decrypted = base64_decode($row['account_number']);
            if ($decrypted !== false) {
                $row['account_number_display'] = $decrypted;
            } else {
                $row['account_number_display'] = '解密失败';
            }
        } catch (Throwable $e) {
            $row['account_number_display'] = '解密失败';
        }

        // 添加类型文本
        $typeMap = [
            'bank_card' => '银行卡',
            'alipay' => '支付宝',
            'wechat' => '微信',
            'usdt' => 'USDT',
        ];
        $accountTypeMap = [
            'personal' => '个人',
            'company' => '公司',
        ];
        $auditStatusMap = [
            0 => '待审核',
            1 => '已通过',
            2 => '已拒绝',
        ];
        
        $row['type_text'] = $typeMap[$row['type']] ?? '未知';
        $row['account_type_text'] = $accountTypeMap[$row['account_type']] ?? '未知';
        $row['audit_status_text'] = $auditStatusMap[$row['audit_status']] ?? '未知';

        $this->success('', [
            'row' => $row
        ]);
    }

    /**
     * 审核通过
     * @throws Throwable
     */
    public function approve(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('ID不能为空');
        }

        $row = Db::name('user_payment_account')->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        // 银行卡类型不需要审核
        if ($row['type'] == 'bank_card') {
            $this->error('银行卡类型不需要审核');
        }

        if ($row['audit_status'] != 0) {
            $this->error('该账户不是待审核状态');
        }

        $remark = $this->request->post('audit_reason', '');
        $adminId = $this->auth->id;

        Db::startTrans();
        try {
            $result = Db::name('user_payment_account')->where('id', $id)->update([
                'audit_status' => 1, // 已通过
                'audit_time' => time(),
                'audit_admin_id' => $adminId,
                'audit_reason' => $remark,
                'update_time' => time(),
            ]);
            
            Db::commit();
            
            if ($result !== false) {
                $this->success('审核通过成功');
            } else {
                $this->error('审核失败');
            }
        } catch (Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    /**
     * 审核拒绝
     * @throws Throwable
     */
    public function reject(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('ID不能为空');
        }

        $row = Db::name('user_payment_account')->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        // 银行卡类型不需要审核
        if ($row['type'] == 'bank_card') {
            $this->error('银行卡类型不需要审核');
        }

        if ($row['audit_status'] != 0) {
            $this->error('该账户不是待审核状态');
        }

        $remark = $this->request->post('audit_reason', '');
        if (empty($remark)) {
            $this->error('拒绝原因不能为空');
        }

        $adminId = $this->auth->id;

        Db::startTrans();
        try {
            $result = Db::name('user_payment_account')->where('id', $id)->update([
                'audit_status' => 2, // 已拒绝
                'audit_time' => time(),
                'audit_admin_id' => $adminId,
                'audit_reason' => $remark,
                'update_time' => time(),
            ]);
            
            Db::commit();
            
            if ($result !== false) {
                $this->success('审核拒绝成功');
            } else {
                $this->error('审核失败');
            }
        } catch (Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取用户的所有绑卡列表
     * @throws Throwable
     */
    public function getUserAccounts(): void
    {
        $userId = $this->request->param('user_id/d', 0);
        if (!$userId) {
            $this->error('用户ID不能为空');
        }

        $list = Db::name('user_payment_account')
            ->alias('pa')
            ->leftJoin('ba_user u', 'pa.user_id = u.id')
            ->field('pa.*, u.username, u.nickname, u.mobile')
            ->where('pa.user_id', $userId)
            ->order('pa.is_default desc, pa.create_time desc')
            ->select()
            ->toArray();

        // 处理数据：解密账号并格式化显示
        foreach ($list as &$item) {
            // 解密账号/卡号（完整显示给管理员）
            try {
                $decrypted = base64_decode($item['account_number']);
                if ($decrypted !== false) {
                    $item['account_number_display'] = $decrypted;
                } else {
                    $item['account_number_display'] = '解密失败';
                }
            } catch (Throwable $e) {
                $item['account_number_display'] = '解密失败';
            }
            
            // 添加类型文本
            $typeMap = [
                'bank_card' => '银行卡',
                'alipay' => '支付宝',
                'wechat' => '微信',
                'usdt' => 'USDT',
            ];
            $accountTypeMap = [
                'personal' => '个人',
                'company' => '公司',
            ];
            $auditStatusMap = [
                0 => '待审核',
                1 => '已通过',
                2 => '已拒绝',
            ];
            
            $item['type_text'] = $typeMap[$item['type']] ?? '未知';
            $item['account_type_text'] = $accountTypeMap[$item['account_type']] ?? '未知';
            $item['audit_status_text'] = $auditStatusMap[$item['audit_status']] ?? '未知';
        }

        $this->success('', [
            'list' => $list
        ]);
    }
}

