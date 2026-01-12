<?php

namespace app\admin\controller\finance;

use Throwable;
use think\facade\Db;
use app\common\controller\Backend;
use app\admin\model\FinanceIncomeLog as FinanceIncomeLogModel;

class IncomeLog extends Backend
{
    /**
     * @var FinanceIncomeLogModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['id', 'order_id'];

    protected array $withJoinTable = ['user', 'product', 'order'];

    protected string|array $defaultSortField = 'id,desc';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new FinanceIncomeLogModel();
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
            ->with(['user', 'product', 'order'])
            ->order($order)
            ->paginate($limit);

        $list = $res->items();
        foreach ($list as &$item) {
            // 返息类型文本
            $item->income_type_text = $this->getIncomeTypeText($item->income_type);
            
            // 状态文本和颜色
            list($statusText, $statusColor) = $this->getStatusInfo($item->status);
            $item->status_text = $statusText;
            $item->status_color = $statusColor;
            
            // 用户昵称
            $item->user_nickname = $item->user ? $item->user->nickname : '';
            
            // 产品名称
            $item->product_name = $item->product ? $item->product->name : '';
            
            // 订单号
            $item->order_no = $item->order ? $item->order->order_no : '';
            
            // 格式化时间
            $item->settle_time_text = $item->settle_time ? date('Y-m-d H:i:s', $item->settle_time) : '';
            $item->create_time_text = date('Y-m-d H:i:s', $item->create_time);
        }

        // 统计信息
        $statistics = $this->getStatistics();

        $this->success('', [
            'list' => $list,
            'total' => $res->total(),
            'remark' => get_route_remark(),
            'statistics' => $statistics,
        ]);
    }

    /**
     * 查看详情
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->with(['user', 'product', 'order'])->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        // 返息类型文本
        $row->income_type_text = $this->getIncomeTypeText($row->income_type);
        
        // 状态文本和颜色
        list($statusText, $statusColor) = $this->getStatusInfo($row->status);
        $row->status_text = $statusText;
        $row->status_color = $statusColor;
        
        // 用户昵称
        $row->user_nickname = $row->user ? $row->user->nickname : '';
        
        // 产品名称
        $row->product_name = $row->product ? $row->product->name : '';
        
        // 订单号
        $row->order_no = $row->order ? $row->order->order_no : '';
        
        // 格式化时间
        $row->settle_time_text = $row->settle_time ? date('Y-m-d H:i:s', $row->settle_time) : '';
        $row->create_time_text = date('Y-m-d H:i:s', $row->create_time);

        $this->success('', [
            'row' => $row,
        ]);
    }

    /**
     * 获取返息类型文本
     */
    protected function getIncomeTypeText(string $type): string
    {
        $typeMap = [
            'daily' => '每日返息',
            'period' => '周期返息',
            'stage' => '阶段返息',
            'principal' => '本金返还',
            'compound' => '复利返息',
        ];
        return $typeMap[$type] ?? $type;
    }

    /**
     * 获取状态信息
     */
    protected function getStatusInfo(int $status): array
    {
        if ($status == 1) {
            return ['已发放', 'success'];
        } else {
            return ['未发放', 'danger'];
        }
    }

    /**
     * 获取统计信息
     */
    protected function getStatistics(): array
    {
        $today = date('Y-m-d');
        $todayStart = strtotime($today);
        $todayEnd = $todayStart + 86400 - 1;

        // 今日返息总额
        $todayTotal = Db::name('finance_income_log')
            ->where('income_date', $today)
            ->where('status', 1)
            ->sum('income_amount') ?: 0;

        // 今日返息笔数
        $todayCount = Db::name('finance_income_log')
            ->where('income_date', $today)
            ->where('status', 1)
            ->count();

        // 总返息总额
        $totalAmount = Db::name('finance_income_log')
            ->where('status', 1)
            ->sum('income_amount') ?: 0;

        // 总返息笔数
        $totalCount = Db::name('finance_income_log')
            ->where('status', 1)
            ->count();

        return [
            'today_total' => round($todayTotal, 2),
            'today_count' => $todayCount,
            'total_amount' => round($totalAmount, 2),
            'total_count' => $totalCount,
        ];
    }
}

