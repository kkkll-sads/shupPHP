<?php

namespace app\admin\controller\finance;

use Throwable;
use think\facade\Db;
use think\exception\HttpResponseException;
use app\common\controller\Backend;
use app\admin\model\RightsDeclaration as RightsDeclarationModel;

class RightsDeclarationReview extends Backend
{
    /**
     * @var RightsDeclarationModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['user_id', 'amount'];

    protected string|array $defaultSortField = 'id desc';

    protected array $withJoinTable = ['user'];

    protected bool $modelSceneValidate = true;

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new RightsDeclarationModel();
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
            ->withJoin($this->withJoinTable, $this->withJoinType)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        // 扁平化用户信息字段
        $items = $res->items();
        foreach ($items as &$item) {
            $item['user_nickname'] = $item['user']['nickname'] ?? '';
            $item['user_mobile'] = $item['user']['mobile'] ?? '';
            unset($item['user']); // 移除嵌套的user对象
        }

        $res->visible(['user' => ['nickname', 'mobile']]);

        $this->success('', [
            'list'   => $items,
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 详情
     * @throws Throwable
     */
    public function detail(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        if (!$id) {
            $this->error('缺少必要参数：' . $pk);
        }

        $row = $this->model->withJoin(['user'], 'LEFT')->find($id);

        if (!$row) {
            $this->error(__('Record not found'));
        }

        // 处理凭证类型文本
        $voucherTypeMap = RightsDeclarationModel::getVoucherTypeMap();
        $row['voucher_type_text'] = $voucherTypeMap[$row['voucher_type']] ?? '未知';

        // 处理状态文本
        $statusMap = RightsDeclarationModel::getStatusMap();
        $row['status_text'] = $statusMap[$row['status']] ?? '未知';

        // 处理图片
        $row['images_array'] = $row['images'] ? json_decode($row['images'], true) : [];

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
        $id = $this->request->post('id');
        if (!$id) {
            $this->error('缺少必要参数：id');
        }

        $row = Db::name('rights_declaration')->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($row['status'] !== 'pending') {
            $this->error('仅待审核状态可操作');
        }

        $remark = $this->request->post('audit_remark', '');
        $rewards = $this->request->post('rewards', []); // 选中的奖励类型
        $adminId = $this->auth->id;
        $auditTime = time();

        Db::startTrans();
        try {
            // 更新审核状态
            Db::name('rights_declaration')->where('id', $id)->update([
                'status' => 'approved',
                'review_admin_id' => $adminId,
                'review_time' => $auditTime,
                'review_remark' => $remark,
                'update_time' => $auditTime,
            ]);

            // 根据申报金额计算奖励
            $rewardMoney = $row['amount']; // 默认奖励金额等于申报金额
            $rewardGreenPower = intval($row['amount'] * 10); // 绿色能量 = 申报金额 * 10
            $rewardConsignmentCoupon = intval($row['amount'] / 10); // 寄售卷 = 申报金额 / 10

            // 获取用户信息
            $user = Db::name('user')->find($row['user_id']);
            if (!$user) {
                throw new \Exception('用户不存在');
            }

            $updateData = ['update_time' => $auditTime];

            // 🔧 修复：根据选中奖励发放对应的奖励到待激活金
            if (in_array('balance', $rewards)) {
                $oldPendingGold = $user['pending_activation_gold'] ?? 0;
                $newPendingGold = $oldPendingGold + $rewardMoney;
                $updateData['pending_activation_gold'] = $newPendingGold;
            }

            if (in_array('green_power', $rewards)) {
                $oldGreenPower = $user['green_power'] ?? 0;
                $newGreenPower = $oldGreenPower + $rewardGreenPower;
                $updateData['green_power'] = $newGreenPower;
            }

            if (in_array('consignment_coupon', $rewards)) {
                $oldConsignmentCoupon = $user['consignment_coupon'] ?? 0;
                $newConsignmentCoupon = $oldConsignmentCoupon + $rewardConsignmentCoupon;
                $updateData['consignment_coupon'] = $newConsignmentCoupon;
            }

            // 更新用户数据
            if (count($updateData) > 1) { // 除了update_time还有其他字段
                Db::name('user')->where('id', $row['user_id'])->update($updateData);
            }

            // 记录用户活动日志 - 审核通过
            Db::name('user_activity_log')->insert([
                'user_id' => $row['user_id'],
                'action_type' => 'rights_declaration_approved',
                'change_field' => 'rights_declaration',
                'change_value' => json_encode(['declaration_id' => $id, 'amount' => $row['amount']], JSON_UNESCAPED_UNICODE),
                'remark' => '确权申报审核通过：金额 ' . $row['amount'] . ' 元',
                'extra' => json_encode([
                    'declaration_id' => $id,
                    'voucher_type' => $row['voucher_type'],
                    'amount' => $row['amount'],
                    'admin_id' => $adminId,
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $auditTime,
            ]);

            // 🔧 修复：记录用户活动日志 - 只记录实际发放的奖励（待激活金）
            if (in_array('balance', $rewards) && $rewardMoney > 0) {
                $oldPendingGold = $user['pending_activation_gold'] ?? 0;
                $newPendingGold = $oldPendingGold + $rewardMoney;
                Db::name('user_activity_log')->insert([
                    'user_id' => $row['user_id'],
                    'action_type' => 'rights_declaration_reward_balance',
                    'change_field' => 'pending_activation_gold',
                    'change_value' => $rewardMoney,
                    'before_value' => $oldPendingGold,
                    'after_value' => $newPendingGold,
                    'remark' => '确权申报审核通过奖励：待激活金 +' . $rewardMoney . ' 元',
                    'extra' => json_encode([
                        'source' => 'rights_declaration_approved',
                        'declaration_id' => $id,
                        'admin_id' => $adminId,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $auditTime,
                ]);

                // 记录资金变动日志
                Db::name('user_money_log')->insert([
                    'user_id' => $row['user_id'],
                    'flow_no' => generateSJSFlowNo($row['user_id']),
                    'batch_no' => generateBatchNo('RIGHTS_REWARD', $id),
                    'biz_type' => 'rights_declaration_reward',
                    'biz_id' => $id,
                    'field_type' => 'pending_activation_gold',
                    'money' => $rewardMoney,
                    'before' => $oldPendingGold,
                    'after' => $newPendingGold,
                    'memo' => '确权申报审核通过奖励：待激活金 +' . $rewardMoney . ' 元',
                    'create_time' => $auditTime,
                ]);
            }

            if (in_array('green_power', $rewards) && $rewardGreenPower > 0) {
                $oldGreenPower = $user['green_power'] ?? 0;
                $newGreenPower = $oldGreenPower + $rewardGreenPower;
                Db::name('user_activity_log')->insert([
                    'user_id' => $row['user_id'],
                    'action_type' => 'rights_declaration_reward_green_power',
                    'change_field' => 'green_power',
                    'change_value' => $rewardGreenPower,
                    'before_value' => $oldGreenPower,
                    'after_value' => $newGreenPower,
                    'remark' => '确权申报审核通过奖励：绿色能量 +' . $rewardGreenPower,
                    'extra' => json_encode([
                        'source' => 'rights_declaration_approved',
                        'declaration_id' => $id,
                        'admin_id' => $adminId,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $auditTime,
                ]);
            }

            if (in_array('consignment_coupon', $rewards) && $rewardConsignmentCoupon > 0) {
                $oldConsignmentCoupon = $user['consignment_coupon'] ?? 0;
                $newConsignmentCoupon = $oldConsignmentCoupon + $rewardConsignmentCoupon;
                Db::name('user_activity_log')->insert([
                    'user_id' => $row['user_id'],
                    'action_type' => 'rights_declaration_reward_consignment_coupon',
                    'change_field' => 'consignment_coupon',
                    'change_value' => $rewardConsignmentCoupon,
                    'before_value' => $oldConsignmentCoupon,
                    'after_value' => $newConsignmentCoupon,
                    'remark' => '确权申报审核通过奖励：寄售卷 +' . $rewardConsignmentCoupon,
                    'extra' => json_encode([
                        'source' => 'rights_declaration_approved',
                        'declaration_id' => $id,
                        'admin_id' => $adminId,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $auditTime,
                ]);
            }

            Db::commit();

            // 构建成功消息
            $rewardNames = [];
            if (in_array('balance', $rewards)) $rewardNames[] = '待激活金';
            if (in_array('green_power', $rewards)) $rewardNames[] = '绿色能量';
            if (in_array('consignment_coupon', $rewards)) $rewardNames[] = '寄售卷';

            $message = '审核通过成功';
            if (!empty($rewardNames)) {
                $message .= '，已发放奖励：' . implode('、', $rewardNames);
            }

            $this->success($message);
        } catch (Throwable $e) {
            Db::rollback();
            // 如果是HttpResponseException（成功响应或错误响应），直接重新抛出
            if ($e instanceof HttpResponseException) {
                throw $e;
            }
            // 其他异常才包装为错误响应
            $this->error('审核失败：' . $e->getMessage());
        }
    }

    /**
     * 审核拒绝
     * @throws Throwable
     */
    public function reject(): void
    {
        $id = $this->request->post('id');
        if (!$id) {
            $this->error('缺少必要参数：id');
        }

        $row = Db::name('rights_declaration')->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($row['status'] !== 'pending') {
            $this->error('仅待审核状态可操作');
        }

        $remark = $this->request->post('audit_remark', '');
        if (!$remark) {
            $this->error('请填写审核备注');
        }

        $adminId = $this->auth->id;
        $auditTime = time();

        Db::startTrans();
        try {
            // 更新审核状态
            Db::name('rights_declaration')->where('id', $id)->update([
                'status' => 'rejected',
                'review_admin_id' => $adminId,
                'review_time' => $auditTime,
                'review_remark' => $remark,
                'update_time' => $auditTime,
            ]);

            // 记录用户活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $row['user_id'],
                'action_type' => 'rights_declaration_rejected',
                'change_field' => 'rights_declaration',
                'change_value' => json_encode(['declaration_id' => $id, 'amount' => $row['amount']], JSON_UNESCAPED_UNICODE),
                'remark' => '确权申报审核拒绝：金额 ' . $row['amount'] . ' 元',
                'extra' => json_encode([
                    'declaration_id' => $id,
                    'voucher_type' => $row['voucher_type'],
                    'amount' => $row['amount'],
                    'admin_id' => $adminId,
                    'reason' => $remark,
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $auditTime,
            ]);

            Db::commit();
            $this->success('审核拒绝成功');
        } catch (Throwable $e) {
            Db::rollback();
            // 如果是HttpResponseException（成功响应或错误响应），直接重新抛出
            if ($e instanceof HttpResponseException) {
                throw $e;
            }
            // 其他异常才包装为错误响应
            $this->error('审核失败：' . $e->getMessage());
        }
    }
}
