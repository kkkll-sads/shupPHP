<?php

namespace app\admin\controller\finance;

use Throwable;
use think\facade\Db;
use app\common\controller\Backend;
use app\admin\model\RechargeOrder as RechargeOrderModel;
use app\admin\model\UserMoneyLog;
use app\common\model\UserActivityLog;
use think\exception\HttpResponseException;

class RechargeOrder extends Backend
{
    /**
     * @var RechargeOrderModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['order_no', 'id', 'user.mobile'];

    protected array $withJoinTable = ['user', 'companyAccount'];

    protected string|array $defaultSortField = 'id desc';

    protected array $noNeedPermission = ['stats'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new RechargeOrderModel();
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

        // 特殊处理 payment_channel 和 status_text 搜索，因为它们是计算字段
        $search = $this->request->get("search/a", []);
        $paymentChannelSearch = null;
        $statusTextSearch = null;
        $filteredSearch = [];

        foreach ($search as $field) {
            if (isset($field['field']) && $field['field'] === 'payment_channel' && isset($field['val']) && !empty($field['val'])) {
                $paymentChannelSearch = $field;
                continue; // 不添加到 filteredSearch 中
            }
            if (isset($field['field']) && $field['field'] === 'status_text' && isset($field['val']) && !empty($field['val'])) {
                $statusTextSearch = $field;
                continue; // 不添加到 filteredSearch 中
            }
            $filteredSearch[] = $field;
        }

        // 临时替换搜索参数，排除 payment_channel 和 status_text
        if ($paymentChannelSearch || $statusTextSearch) {
            $this->request->withGet(['search' => $filteredSearch]);
        }

        [$where, $alias, $limit, $order] = $this->queryBuilder();

        // 恢复原始搜索参数
        if ($paymentChannelSearch || $statusTextSearch) {
            $this->request->withGet(['search' => $search]);
        }

        // 处理 payment_channel 搜索
        if ($paymentChannelSearch) {
            $operator = isset($paymentChannelSearch['operator']) ? $this->getOperatorByAlias($paymentChannelSearch['operator']) : 'LIKE';
            $searchValue = $paymentChannelSearch['val'];

            // payment_channel 是 company_account.account_name + ' (' + company_account.bank_name + ')'
            if ($operator === 'LIKE') {
                // LIKE 搜索：支持部分匹配
                $where[] = function($query) use ($searchValue) {
                    $query->whereRaw("CONCAT(company_account.account_name, ' (', company_account.bank_name, ')') LIKE ?", ['%' . str_replace('%', '\%', $searchValue) . '%']);
                };
            } elseif ($operator === '=') {
                // 精确匹配：匹配完整的支付通道名称
                $where[] = function($query) use ($searchValue) {
                    $query->whereRaw("CONCAT(company_account.account_name, ' (', company_account.bank_name, ')') = ?", [$searchValue]);
                };
            }
        }

        // 处理 status_text 搜索
        if ($statusTextSearch) {
            $searchValue = $statusTextSearch['val'];
            $operator = isset($statusTextSearch['operator']) ? $statusTextSearch['operator'] : 'select';

            // status_text 映射关系：
            // - '待审核': status = 0 AND payment_type != 'online'
            // - '待支付': status = 0 AND payment_type = 'online'
            // - '已通过': status = 1 AND (payment_type != 'online' OR audit_remark != '线上支付自动到账')
            // - '已拒绝': status = 2
            // - '线上支付已到账': status = 1 AND payment_type = 'online' AND audit_remark = '线上支付自动到账'

            if ($operator === 'select') {
                // 精确匹配状态文本
                if ($searchValue === '待审核') {
                    $where[] = function($query) {
                        $query->where('recharge_order.status', 0)->where('recharge_order.payment_type', '<>', 'online');
                    };
                } elseif ($searchValue === '待支付') {
                    $where[] = function($query) {
                        $query->where('recharge_order.status', 0)->where('recharge_order.payment_type', 'online');
                    };
                } elseif ($searchValue === '已通过') {
                    $where[] = function($query) {
                        $query->where('recharge_order.status', 1)->where(function($subQuery) {
                            $subQuery->where('recharge_order.payment_type', '<>', 'online')
                                    ->whereOr('recharge_order.audit_remark', '<>', '线上支付自动到账');
                        });
                    };
                } elseif ($searchValue === '已拒绝') {
                    $where[] = ['recharge_order.status', '=', 2];
                } elseif ($searchValue === '线上支付已到账') {
                    $where[] = function($query) {
                        $query->where('recharge_order.status', 1)
                              ->where('recharge_order.payment_type', 'online')
                              ->where('recharge_order.audit_remark', '线上支付自动到账');
                    };
                }
            }
        }

        $res = $this->model
            ->alias($alias)
            ->where($where)
            ->withJoin($this->withJoinTable, $this->withJoinType)
            ->order($order)
            ->paginate($limit);

        $list = $res->items();
        foreach ($list as &$item) {
            // 支付方式文本
            $paymentTypeMap = [
                'bank_card' => '银行卡',
                'alipay' => '支付宝',
                'wechat' => '微信',
                'usdt' => 'USDT',
                'online' => '线上支付',
            ];
            $item->payment_type_text = $paymentTypeMap[$item->payment_type] ?? '未知';

            // 添加支付通道信息
            $item->payment_channel = '';
            if ($item->company_account) {
                $item->payment_channel = $item->company_account->account_name . ' (' . $item->company_account->bank_name . ')';
            }

            // 订单状态文本和颜色
            $statusMap = [
                0 => ['text' => '待审核', 'color' => 'warning'],
                1 => ['text' => '已通过', 'color' => 'success'],
                2 => ['text' => '已拒绝', 'color' => 'danger'],
            ];
            $statusInfo = $statusMap[$item->status] ?? ['text' => '未知', 'color' => 'info'];

            // 特殊处理线上支付订单
            if ($item->payment_type === 'online') {
                if ($item->status == 1 && $item->audit_remark === '线上支付自动到账') {
                    $statusInfo = ['text' => '线上支付已到账', 'color' => 'success'];
                } elseif ($item->status == 0) {
                    $statusInfo = ['text' => '待支付', 'color' => 'primary'];
                }
            }

            $item->status_text = $statusInfo['text'];
            $item->status_color = $statusInfo['color'];

            // 格式化时间
            $item->create_time_text = date('Y-m-d H:i:s', $item->create_time);
            $item->audit_time_text = $item->audit_time ? date('Y-m-d H:i:s', $item->audit_time) : '';
        }

        $this->success('', [
            'list' => $list,
            'total' => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 查看详情
     * @throws Throwable
     */
    public function read(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->with(['user', 'companyAccount'])->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        // 支付方式文本
        $paymentTypeMap = [
            'bank_card' => '银行卡',
            'alipay' => '支付宝',
            'wechat' => '微信',
            'usdt' => 'USDT',
            'online' => '线上支付',
        ];
        $row->payment_type_text = $paymentTypeMap[$row->payment_type] ?? '未知';
        
        // 订单状态文本
        $statusMap = [
            0 => '待审核',
            1 => '已通过',
            2 => '已拒绝',
        ];
        $row->status_text = $statusMap[$row->status] ?? '未知';
        
        // 格式化时间
        $row->create_time_text = date('Y-m-d H:i:s', $row->create_time);
        $row->audit_time_text = $row->audit_time ? date('Y-m-d H:i:s', $row->audit_time) : '';

        // 添加支付通道信息
        $row->payment_channel = '';
        if ($row->company_account) {
            $row->payment_channel = $row->company_account->account_name . ' (' . $row->company_account->bank_name . ')';
        }

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
        $pk = $this->model->getPk();
        $id = $this->request->post($pk);
        if (!$id) {
            $id = $this->request->param($pk);
        }
        if (!$id) {
            $this->error('缺少必要参数：' . $pk);
        }
        
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        // 只有待审核状态才能审核通过
        if ($row->status != 0) {
            $this->error('该订单状态不允许审核通过');
        }

        $remark = $this->request->post('audit_remark', '');
        $adminId = $this->auth->id;
        
        if (!$adminId) {
            $this->error('管理员ID不能为空，请重新登录');
        }

        Db::startTrans();
        try {
            // 1. 更新订单状态
            $row->status = 1; // 已通过
            $row->audit_admin_id = $adminId;
            $row->audit_time = time();
            $row->audit_remark = $remark;
            $row->save();

            // 2. 增加用户余额
            $user = Db::name('user')->where('id', $row->user_id)->lock(true)->find();
            if (!$user) {
                throw new \Exception('用户不存在');
            }

            $beforeBalance = (float)$user['balance_available'];
            $beforeMoney = (float)$user['money'];
            $afterBalance = $beforeBalance + (float)$row->amount;
            $afterMoney = $beforeMoney + (float)$row->amount;

            // 3. 记录余额变动日志
            $moneyLog = new UserMoneyLog();
            $moneyLog->user_id = $row->user_id;
            $moneyLog->money = $row->amount;
            $moneyLog->before = $beforeBalance;
            $moneyLog->after = $afterBalance;
            $moneyLog->memo = '充值订单审核通过：' . $row->order_no;
            $moneyLog->save();

            $updateData = [
                'balance_available' => $afterBalance,
                'money' => $afterMoney,
                'update_time' => time(),
            ];

            // 5. 检查并赠送算力（根据活动配置）
            $rewardPowerRate = (float)Db::name('config')
                ->where('name', 'recharge_reward_power_rate')
                ->where('group', 'activity_reward')
                ->value('value', 0);
            
            $rewardPower = 0;
            if ($rewardPowerRate > 0) {
                $rewardPower = round((float)$row->amount * $rewardPowerRate / 100, 2);
                if ($rewardPower > 0) {
                    $beforeGreenPower = (float)($user['green_power'] ?? 0);
                    $afterGreenPower = round($beforeGreenPower + $rewardPower, 2);
                    $updateData['green_power'] = $afterGreenPower;
                }
            }

            Db::name('user')
                ->where('id', $row->user_id)
                ->update($updateData);

            // 4. 记录用户活动日志
            UserActivityLog::create([
                'user_id' => $row->user_id,
                'related_user_id' => 0,
                'action_type' => 'balance',
                'change_field' => 'balance_available',
                'change_value' => (string)$row->amount,
                'before_value' => (string)$beforeBalance,
                'after_value' => (string)$afterBalance,
                'remark' => '充值订单审核通过，订单号：' . $row->order_no,
                'extra' => [
                    'order_id' => $row->id,
                    'order_no' => $row->order_no,
                    'amount' => (string)$row->amount,
                    'payment_type' => $row->payment_type,
                    'audit_admin_id' => $adminId,
                    'audit_remark' => $remark,
                ],
            ]);

            // 6. 如果赠送了算力，记录算力变动日志和活动日志
            if ($rewardPower > 0) {
                $beforeGreenPower = (float)($user['green_power'] ?? 0);
                $afterGreenPower = round($beforeGreenPower + $rewardPower, 2);
                $now = time();
                
                // 记录算力变动日志
                Db::name('user_money_log')->insert([
                    'user_id' => $row->user_id,
                    'field_type' => 'green_power',
                    'money' => $rewardPower,
                    'before' => $beforeGreenPower,
                    'after' => $afterGreenPower,
                    'memo' => '充值奖励-绿色算力：订单号 ' . $row->order_no,
                    'flow_no' => generateSJSFlowNo($row->user_id),
                    'batch_no' => generateBatchNo('RECHARGE_REWARD', $row->id),
                    'biz_type' => 'recharge_reward',
                    'biz_id' => $row->id,
                    'create_time' => $now,
                ]);

                // 记录活动日志
                UserActivityLog::create([
                    'user_id' => $row->user_id,
                    'related_user_id' => 0,
                    'action_type' => 'recharge_reward',
                    'change_field' => 'green_power',
                    'change_value' => (string)$rewardPower,
                    'before_value' => (string)$beforeGreenPower,
                    'after_value' => (string)$afterGreenPower,
                    'remark' => '充值奖励-绿色算力：+' . $rewardPower . '（充值金额：' . $row->amount . '元，奖励比例：' . $rewardPowerRate . '%）',
                    'extra' => [
                        'order_id' => $row->id,
                        'order_no' => $row->order_no,
                        'amount' => (string)$row->amount,
                        'reward_power_rate' => (string)$rewardPowerRate,
                        'reward_power' => (string)$rewardPower,
                        'audit_admin_id' => $adminId,
                    ],
                ]);
            }

            Db::commit();
            $this->success('审核通过成功，用户余额已增加');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('审核失败：' . $e->getMessage());
        }
    }

    /**
     * 审核拒绝
     * @throws Throwable
     */
    public function reject(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->post($pk);
        if (!$id) {
            $id = $this->request->param($pk);
        }
        if (!$id) {
            $this->error('缺少必要参数：' . $pk);
        }
        
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        // 只有待审核状态才能拒绝
        if ($row->status != 0) {
            $this->error('该订单状态不允许审核拒绝');
        }

        $remark = $this->request->post('audit_remark', '');
        if (empty($remark)) {
            $this->error('拒绝原因不能为空');
        }

        $adminId = $this->auth->id;
        
        if (!$adminId) {
            $this->error('管理员ID不能为空，请重新登录');
        }

        Db::startTrans();
        try {
            $row->status = 2; // 已拒绝
            $row->audit_admin_id = $adminId;
            $row->audit_time = time();
            $row->audit_remark = $remark;
            $row->save();

            Db::commit();
            $this->success('审核拒绝成功');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('审核失败：' . $e->getMessage());
        }
    }

    /**
     * 统计接口
     */
    public function stats(): void
    {
        $stats = [];

        // 按状态统计
        $statusStats = Db::name('recharge_order')
            ->field('status, COUNT(*) as count, SUM(amount) as total_amount')
            ->group('status')
            ->select()
            ->toArray();

        $statusMap = [
            0 => '待审核',
            1 => '已通过',
            2 => '已拒绝',
        ];

        foreach ($statusMap as $key => $name) {
            $stats['status'][$key] = ['name' => $name, 'count' => 0, 'total_amount' => 0];
        }
        foreach ($statusStats as $item) {
            $stats['status'][$item['status']] = [
                'name' => $statusMap[$item['status']] ?? '未知',
                'count' => (int)$item['count'],
                'total_amount' => round((float)$item['total_amount'], 2),
            ];
        }

        // 总计
        $stats['total'] = Db::name('recharge_order')->count();
        $stats['total_approved_amount'] = (float)Db::name('recharge_order')->where('status', 1)->sum('amount');

        // 今日统计
        $stats['today_new'] = Db::name('recharge_order')
            ->where('create_time', '>=', strtotime('today'))
            ->count();
        $stats['today_approved'] = Db::name('recharge_order')
            ->where('status', 1)
            ->where('audit_time', '>=', strtotime('today'))
            ->count();
        $stats['today_approved_amount'] = (float)Db::name('recharge_order')
            ->where('status', 1)
            ->where('audit_time', '>=', strtotime('today'))
            ->sum('amount');

        // 按银行通道统计 (TOP 20)
        $todayStart = strtotime('today');
        $bankStats = Db::name('recharge_order')
            ->alias('ro')
            ->leftJoin('company_payment_account ca', 'ro.company_account_id = ca.id')
            ->field("ca.bank_name, COUNT(*) as count, 
                SUM(CASE WHEN ro.create_time >= {$todayStart} THEN 1 ELSE 0 END) as today_count,
                SUM(CASE WHEN ro.create_time >= {$todayStart} THEN ro.amount ELSE 0 END) as today_amount,
                SUM(CASE WHEN ro.status = 1 AND ro.audit_time >= {$todayStart} THEN 1 ELSE 0 END) as today_approved_count,
                SUM(CASE WHEN ro.status = 1 AND ro.audit_time >= {$todayStart} THEN ro.amount ELSE 0 END) as today_approved_amount,
                SUM(CASE WHEN ro.status = 0 THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN ro.status = 1 THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN ro.status = 2 THEN 1 ELSE 0 END) as rejected_count,
                SUM(ro.amount) as total_amount,
                SUM(CASE WHEN ro.status = 1 THEN ro.amount ELSE 0 END) as approved_amount")
            ->group('ca.bank_name')
            ->order('count desc')
            ->limit(20)
            ->select()
            ->toArray();

        // 格式化银行统计
        foreach ($bankStats as &$b) {
            $b['bank_name'] = $b['bank_name'] ?: '未知通道';
            $b['today_count'] = (int)($b['today_count'] ?? 0);
            $b['today_amount'] = round((float)($b['today_amount'] ?? 0), 2);
            $b['today_approved_count'] = (int)($b['today_approved_count'] ?? 0);
            $b['today_approved_amount'] = round((float)($b['today_approved_amount'] ?? 0), 2);
            $b['pending_count'] = (int)($b['pending_count'] ?? 0);
            $b['approved_count'] = (int)($b['approved_count'] ?? 0);
            $b['rejected_count'] = (int)($b['rejected_count'] ?? 0);
            $b['total_amount'] = round((float)($b['total_amount'] ?? 0), 2);
            $b['approved_amount'] = round((float)($b['approved_amount'] ?? 0), 2);
            $b['approval_rate'] = $b['count'] > 0 ? round(($b['approved_count'] / $b['count']) * 100, 1) : 0;
        }

        $stats['banks'] = $bankStats;

        // 获取银行选项（用于筛选器）
        $bankOptions = Db::name('recharge_order')
            ->alias('ro')
            ->leftJoin('company_payment_account ca', 'ro.company_account_id = ca.id')
            ->field('DISTINCT ca.bank_name')
            ->whereNotNull('ca.bank_name')
            ->where('ca.bank_name', '<>', '')
            ->order('ca.bank_name asc')
            ->select()
            ->toArray();

        $stats['bank_options'] = array_column($bankOptions, 'bank_name');

        $this->success('', [
            'stats' => $stats,
        ]);
    }
}
