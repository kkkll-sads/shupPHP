<?php

namespace app\admin\controller\user;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\User as UserModel;
use app\common\model\UserActivityLog;
use think\facade\Db;
use think\exception\HttpResponseException;

class User extends Backend
{
    /**
     * @var object
     * @phpstan-var UserModel
     */
    protected object $model;

    protected array $withJoinTable = ['userGroup', 'inviteCode'];

    // 排除字段
    protected string|array $preExcludeFields = ['last_login_time', 'login_failure', 'password', 'salt'];

    protected string|array $quickSearchField = ['username', 'nickname', 'id'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new UserModel();
        
        // 为没有邀请码的用户自动生成邀请码
        $this->ensureAllUsersHaveInviteCode();
    }
    
    /**
     * 确保所有用户都有邀请码
     */
    protected function ensureAllUsersHaveInviteCode(): void
    {
        try {
            // 获取所有用户ID
            $allUserIds = $this->model->column('id');
            // 获取已有邀请码的用户ID
            $userIdsWithCode = \think\facade\Db::name('invite_code')->column('user_id');
            // 找出没有邀请码的用户
            $usersWithoutCode = array_diff($allUserIds, $userIdsWithCode);
            
            if (!empty($usersWithoutCode)) {
                foreach ($usersWithoutCode as $userId) {
                    $inviteCode = $this->generateUniqueInviteCode();
                    \think\facade\Db::name('invite_code')->insert([
                        'code' => $inviteCode,
                        'user_id' => $userId,
                        'status' => '1',
                        'use_count' => 0,
                        'max_use' => 0,
                        'create_time' => time(),
                        'update_time' => time(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // 静默失败，不影响正常功能
        }
    }
    
    /**
     * 生成唯一的邀请码
     */
    protected function generateUniqueInviteCode(): string
    {
        do {
            // 生成6位随机字母数字邀请码
            $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
        } while (\think\facade\Db::name('invite_code')->where('code', $code)->find());
        
        return $code;
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

        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $res = $this->model
            ->withoutField('password,salt')
            ->withJoin($this->withJoinTable, $this->withJoinType)
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        // 获取用户列表并添加可用寄售券数量和可解锁次数
        $list = $res->items();
        $userIds = array_column($list, 'id');
        
        // 批量获取可用寄售券数量
        if (!empty($userIds)) {
            $couponCounts = Db::name('user_consignment_coupon')
                ->where('user_id', 'in', $userIds)
                ->where('status', 1)
                ->field('user_id, COUNT(*) as count')
                ->group('user_id')
                ->select()
                ->column('count', 'user_id');
            
            // 批量获取交易直推数量（用于计算可解锁次数）
            $referralCounts = Db::name('collection_order')
                ->alias('o')
                ->join('user u', 'o.user_id = u.id')
                ->where('u.inviter_id', 'in', $userIds)
                ->whereIn('o.status', ['paid', 'completed'])
                ->field('u.inviter_id as inviter_id, COUNT(DISTINCT o.user_id) as count')
                ->group('u.inviter_id')
                ->select()
                ->column('count', 'inviter_id');
            
            // 获取配置的每N个直推=1次解锁资格
            $referralsRequired = (int)get_sys_config('old_assets_condition_referrals') ?: 3;
            
            foreach ($list as &$item) {
                $userId = $item['id'];
                $item['available_coupon_count'] = $couponCounts[$userId] ?? 0;
                
                // 计算可解锁次数（包含额外资格）
                $qualifiedReferrals = $referralCounts[$userId] ?? 0;
                $unlockedCount = (int)($item['old_assets_unlock_count'] ?? 0);
                $bonusQuota = (int)($item['bonus_unlock_quota'] ?? 0);
                $earnedQuota = floor($qualifiedReferrals / $referralsRequired);
                $availableQuota = max(0, $earnedQuota + $bonusQuota - $unlockedCount);
                
                $item['old_assets_available_quota'] = $availableQuota;
            }
        }

        $this->success('', [
            'list'   => $list,
            'total'  => $res->total(),
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

            $result = false;
            $passwd = $data['password']; // 密码将被排除不直接入库
            $data   = $this->excludeFields($data);

            $this->model->startTrans();
            try {
                // 模型验证
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) $validate->scene('add');
                        $validate->check($data);
                    }
                }
                $result = $this->model->save($data);
                $this->model->commit();

                if (!empty($passwd)) {
                    $this->model->resetPassword($this->model->id, $passwd);
                }
            } catch (Throwable $e) {
                $this->model->rollback();
                $this->error($e->getMessage());
            }
            if ($result !== false) {
                $this->success(__('Added successfully'));
            } else {
                $this->error(__('No rows were added'));
            }
        }

        $this->error(__('Parameter error'));
    }

    /**
     * 编辑
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk  = $this->model->getPk();
        $id  = $this->request->param($pk);
        $row = $this->model->with(['inviteCode'])->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        $dataLimitAdminIds = $this->getDataLimitAdminIds();
        if ($dataLimitAdminIds && !in_array($row[$this->dataLimitField], $dataLimitAdminIds)) {
            $this->error(__('You have no permission'));
        }

        if ($this->request->isPost()) {
            $data = $this->request->post();
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            // 处理登录密码
            $password = $data['password'] ?? '';
            if ($password) {
                $this->model->resetPassword($id, $password);
            }
            
            // 处理支付密码（6位数字）
            $payPassword = $data['pay_password'] ?? '';
            if ($payPassword !== '') {
                // 验证支付密码格式：必须为6位数字
                if (!preg_match('/^\d{6}$/', $payPassword)) {
                    $this->error('支付密码必须为6位数字');
                }
                // 支付密码不加密，直接存储，保留在数据中
            } else {
                // 如果支付密码为空，从数据中移除，避免清空现有密码
                unset($data['pay_password']);
            }

            $data = $this->excludeFields($data);

            // 金额类字段防溢出校正（decimal(10,2) unsigned 上限 99999999.99）
            // 注意：money字段是派生值，不允许直接修改，从列表中移除
            $moneyMax = 99999999.99;
            $moneyFields = ['withdrawable_money', 'balance_available', 'service_fee_balance', 'score', 'service_fee_balance', 'pending_activation_gold'];
            foreach ($moneyFields as $field) {
                if (isset($data[$field])) {
                    $val = (float)$data[$field];
                    if ($val < 0) $val = 0;
                    if ($val > $moneyMax) $val = $moneyMax;
                    $data[$field] = $val;
                }
            }
            
            // 如果管理员尝试修改money字段，移除该字段并给出提示
            if (isset($data['money'])) {
                unset($data['money']);
                // money是派生值，会自动计算，不需要也不允许手动设置
            }

            $result = false;
            $this->model->startTrans();
            try {
                // 模型验证
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) $validate->scene('edit');
                        $data[$pk] = $row[$pk];
                        $validate->check($data);
                    }
                }
                
                // 记录需要记录活动日志的字段（移除money，因为它是派生值）
                $logFields = ['withdrawable_money', 'balance_available', 'score', 'service_fee_balance', 'consignment_coupon', 'green_power'];
                $actionTypeMap = [
                    'withdrawable_money' => 'withdrawable_money',
                    'balance_available' => 'balance_available',
                    'score' => 'score',
                    'service_fee_balance' => 'service_fee_balance',
                    'consignment_coupon' => 'consignment_coupon',
                    'green_power' => 'green_power',
                ];
                $fieldLabelMap = [
                    'balance_available' => '可用余额',
                    'withdrawable_money' => '可提现金额',
                    'score' => '消费金',
                    'service_fee_balance' => '服务金额',
                    'consignment_coupon' => '寄售券',
                    'green_power' => '绿色算力',
                ];
                
                // 检查并记录字段变更
                foreach ($logFields as $field) {
                    if (isset($data[$field])) {
                        $oldValue = $row[$field] ?? 0;
                        $newValue = $data[$field];
                        
                        // 对于积分字段，使用整数类型；其他字段使用浮点数
                        if ($field === 'score') {
                            $oldValueNum = is_numeric($oldValue) ? (int)$oldValue : 0;
                            $newValueNum = is_numeric($newValue) ? (int)$newValue : 0;
                        } else {
                            $oldValueNum = is_numeric($oldValue) ? (float)$oldValue : 0;
                            $newValueNum = is_numeric($newValue) ? (float)$newValue : 0;
                        }
                        
                        // 如果值有变化，记录活动日志
                        if ($oldValueNum != $newValueNum) {
                            $changeValue = $newValueNum - $oldValueNum;
                            
                            UserActivityLog::create([
                                'user_id' => $id,
                                'related_user_id' => 0,
                                'action_type' => $actionTypeMap[$field],
                                'change_field' => $field,
                                'change_value' => (string)$changeValue,
                                'before_value' => (string)$oldValueNum,
                                'after_value' => (string)$newValueNum,
                                'remark' => $fieldLabelMap[$field] . '调整',
                                'extra' => [
                                    'operator' => 'admin',
                                    'admin_id' => $this->auth->id ?? 0,
                                ],
                            ]);

                            // 🆕 专门为绿色算力增加资金流水记录（UserMoneyLog）
                            // 只有green_power需要这样处理，因为其他资金字段可能有专门的变更逻辑，或者已经废弃使用money字段
                            if ($field === 'green_power') {
                                Db::name('user_money_log')->insert([
                                    'user_id' => $id,
                                    'field_type' => 'green_power',
                                    'money' => $changeValue,
                                    'before' => $oldValueNum,
                                    'after' => $newValueNum,
                                    'memo' => '后台调整-绿色算力', // 与活动日志备注区分
                                    'create_time' => time(),
                                    'extra_json' => json_encode([
                                        'operator' => 'admin',
                                        'admin_id' => $this->auth->id ?? 0,
                                    ]),
                                ]);
                            }
                        }
                    }
                }
                
                // 🆕 检查实名状态变更，触发邀请奖励
                if (isset($data['real_name_status'])) {
                    $oldRealNameStatus = (int)($row['real_name_status'] ?? 0);
                    $newRealNameStatus = (int)$data['real_name_status'];
                    
                    // 如果实名状态从非"已通过"变为"已通过"（2），触发邀请奖励
                    if ($oldRealNameStatus != 2 && $newRealNameStatus == 2) {
                        $inviterId = (int)($row['inviter_id'] ?? 0);
                        if ($inviterId > 0) {
                            try {
                                // 调用邀请奖励逻辑
                                $listener = new \app\listener\UserRegisterSuccess();
                                $listener->handleInviteReward($inviterId, $id);
                                
                                \think\facade\Log::info("后台用户管理修改实名状态触发邀请奖励成功：被邀请人ID={$id}, 邀请人ID={$inviterId}");
                            } catch (\Throwable $e) {
                                // 邀请奖励发放失败不影响实名审核结果
                                \think\facade\Log::error("后台用户管理修改实名状态触发邀请奖励失败：被邀请人ID={$id}, 邀请人ID={$inviterId}, 错误：" . $e->getMessage());
                            }
                        }
                    }
                }
                
                $result = $row->save($data);
                $this->model->commit();
            } catch (Throwable $e) {
                $this->model->rollback();
                $this->error($e->getMessage());
            }
            if ($result !== false) {
                $this->success(__('Update successful'));
            } else {
                $this->error(__('No rows updated'));
            }
        }

        unset($row->salt);
        $row->password = '';
        $row->pay_password = ''; // 支付密码不显示实际值
        $this->success('', [
            'row' => $row
        ]);
    }

    /**
     * 重写select
     * @throws Throwable
     */
    public function select(): void
    {
        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $res = $this->model
            ->withoutField('password,salt')
            ->withJoin($this->withJoinTable, $this->withJoinType)
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        foreach ($res as $re) {
            $re->nickname_text = $re->username . '(ID:' . $re->id . ')';
        }

        $this->success('', [
            'list'   => $res->items(),
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 删除（重写父类方法，添加清理关联邀请码的逻辑）
     * @throws Throwable
     */
    public function del(): void
    {
        $where             = [];
        $dataLimitAdminIds = $this->getDataLimitAdminIds();
        if ($dataLimitAdminIds) {
            $where[] = [$this->dataLimitField, 'in', $dataLimitAdminIds];
        }

        $ids     = $this->request->param('ids/a', []);
        $where[] = [$this->model->getPk(), 'in', $ids];
        $data    = $this->model->where($where)->select();

        $count = 0;
        Db::startTrans();
        try {
            foreach ($data as $v) {
                $userId = $v->id;
                
                // 删除用户关联的邀请码
                Db::name('invite_code')->where('user_id', $userId)->delete();
                
                // 删除用户关联的邀请记录（作为被邀请人）
                Db::name('invite_record')->where('user_id', $userId)->delete();
                
                // 删除用户关联的邀请记录（作为邀请人）
                Db::name('invite_record')->where('inviter_id', $userId)->delete();
                
                // 删除用户活动日志
                Db::name('user_activity_log')->where('user_id', $userId)->delete();
                Db::name('user_activity_log')->where('related_user_id', $userId)->delete();
                
                // 删除用户积分日志
                Db::name('user_score_log')->where('user_id', $userId)->delete();
                
                // 删除用户金额日志
                Db::name('user_money_log')->where('user_id', $userId)->delete();
                
                // 删除用户签到记录
                Db::name('user_sign_in')->where('user_id', $userId)->delete();
                
                // 删除抽奖记录
                Db::name('lucky_draw_record')->where('user_id', $userId)->delete();
                
                // 删除用户本身
                $count += $v->delete();
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        if ($count) {
            $this->success(__('Deleted successfully'));
        } else {
            $this->error(__('No rows were deleted'));
        }
    }

    /**
     * 清理孤立的邀请码（没有关联用户的邀请码）
     * @throws Throwable
     */
    public function cleanOrphanedInviteCodes(): void
    {
        try {
            Db::startTrans();
            try {
                // 先统计孤立的邀请码数量
                $orphanedCount = Db::name('invite_code')
                    ->alias('ic')
                    ->leftJoin('user u', 'ic.user_id = u.id')
                    ->whereNull('u.id')
                    ->count();
                
                if ($orphanedCount == 0) {
                    Db::commit();
                    $this->success('没有需要清理的孤立邀请码');
                }
                
                // 使用子查询删除孤立的邀请码（更高效）
                $deletedCount = Db::execute("
                    DELETE ic FROM ba_invite_code ic
                    LEFT JOIN ba_user u ON ic.user_id = u.id
                    WHERE u.id IS NULL
                ");
                
                Db::commit();
                
                $this->success("成功清理 {$deletedCount} 个孤立的邀请码");
            } catch (HttpResponseException $e) {
                throw $e;
            } catch (Throwable $e) {
                Db::rollback();
                throw $e;
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error('清理失败：' . $e->getMessage());
        }
    }

    /**
     * 清除全部用户（保留最新的用户）
     * @throws Throwable
     */
    public function clearAllExceptLatest(): void
    {
        try {
            // 获取最新的用户ID（按创建时间或ID降序，取第一个）
            $latestUser = $this->model->order('id', 'desc')->find();
            
            if (!$latestUser) {
                $this->error('没有可清除的用户');
            }

            $totalCount = $this->model->count();
            if ($totalCount <= 1) {
                $this->success('只有1个用户，无需清除');
            }

            Db::startTrans();
            try {
                // 删除除最新用户外的所有用户
                $deleted = Db::name('user')
                    ->where('id', '<>', $latestUser->id)
                    ->delete();
                
                // 同时清除相关的关联数据
                if ($deleted !== false) {
                    // 清除用户活动日志
                    Db::name('user_activity_log')->where('user_id', '<>', $latestUser->id)->delete();
                    Db::name('user_activity_log')->where('related_user_id', '<>', $latestUser->id)->where('related_user_id', '<>', 0)->delete();
                    
                    // 清除用户积分日志
                    Db::name('user_score_log')->where('user_id', '<>', $latestUser->id)->delete();
                    
                    // 清除用户金额日志
                    Db::name('user_money_log')->where('user_id', '<>', $latestUser->id)->delete();
                    
                    // 清除用户签到记录
                    Db::name('user_sign_in')->where('user_id', '<>', $latestUser->id)->delete();
                    
                    // 清除抽奖记录
                    Db::name('lucky_draw_record')->where('user_id', '<>', $latestUser->id)->delete();
                    
                    // 清除邀请码（保留最新用户的）
                    Db::name('invite_code')->where('user_id', '<>', $latestUser->id)->delete();
                    
                    // 清除邀请记录
                    Db::name('invite_record')->where('user_id', '<>', $latestUser->id)->delete();
                    Db::name('invite_record')->where('inviter_id', '<>', $latestUser->id)->delete();
                }
                
                Db::commit();
                
                $deletedCount = $totalCount - 1;
                // 注意：success 会抛出 HttpResponseException，这里不要被后续 Throwable 捕获
                $this->success("成功清除 {$deletedCount} 个用户，已保留最新用户（ID: {$latestUser->id}）");
            } catch (HttpResponseException $e) {
                // HttpResponseException 是 success/error 方法抛出的正常响应异常
                // 此时事务已经 commit，不需要 rollback，直接重新抛出
                throw $e;
            } catch (Throwable $e) {
                Db::rollback();
                throw $e;
            }
        } catch (HttpResponseException $e) {
            // 正常响应，直接重新抛出
            throw $e;
        } catch (Throwable $e) {
            $this->error('清除失败：' . $e->getMessage());
        }
    }
}