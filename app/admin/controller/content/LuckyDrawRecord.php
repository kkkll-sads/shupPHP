<?php

namespace app\admin\controller\content;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\LuckyDrawRecord as LuckyDrawRecordModel;
use app\admin\model\LuckyDrawPrize as LuckyDrawPrizeModel;
use think\facade\Db;

class LuckyDrawRecord extends Backend
{
    /**
     * @var object
     * @phpstan-var LuckyDrawRecordModel
     */
    protected object $model;

    protected string|array $preExcludeFields = [];
    protected string|array $quickSearchField = ['prize_name', 'user_id', 'id'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new LuckyDrawRecordModel();
    }

    /**
     * 列表
     * @throws Throwable
     */
    public function index(): void
    {
        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $res = $this->model
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        $items = $res->items();
        foreach ($items as &$item) {
            $item['draw_time_text'] = date('Y-m-d H:i:s', $item['draw_time']);
            if ($item['send_time']) {
                $item['send_time_text'] = date('Y-m-d H:i:s', $item['send_time']);
            }
        }

        $this->success('', [
            'list'   => $items,
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 编辑
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk  = $this->model->getPk();
        $id  = $this->request->param($pk);
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($this->request->isPost()) {
            $data = $this->request->post();
            $oldStatus = $row['status'];
            $newStatus = isset($data['status']) ? (int)$data['status'] : $oldStatus;
            
            // 如果状态从非"已发放"变为"已发放"，自动发放奖品
            if ($oldStatus != 2 && $newStatus == 2) {
                // 只对积分和余额类型的奖品自动发放
                if (in_array($row['prize_type'], ['score', 'money'])) {
                    try {
                        \app\common\library\LuckyDraw::sendPrize($id, $row['user_id'], $row['prize_type'], $row['prize_value']);
                    } catch (\Throwable $e) {
                        $this->error('发放奖品失败：' . $e->getMessage());
                    }
                } else {
                    // 其他类型只更新状态和时间
                    $row->update(['status' => 2, 'send_time' => time()]);
                }
            } else {
                $row->update($data);
            }
            $this->success('');
        } else {
            $row = $row->toArray();
            $row['draw_time_text'] = date('Y-m-d H:i:s', $row['draw_time']);
            if ($row['send_time']) {
                $row['send_time_text'] = date('Y-m-d H:i:s', $row['send_time']);
            }

            $this->success('', [
                'row' => $row
            ]);
        }
    }

    /**
     * 删除
     * @throws Throwable
     */
    public function delete(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($row->delete()) {
            $this->success('');
        } else {
            $this->error(__('No rows were deleted'));
        }
    }

    /**
     * 手动发放奖品
     * @throws Throwable
     */
    public function sendPrize(): void
    {
        try {
            $pk = $this->model->getPk();
            $id = $this->request->param($pk);
            $row = $this->model->find($id);
            if (!$row) {
                $this->error(__('Record not found'));
            }

            if ($row['status'] == 2) {
                $this->error('该奖品已发放');
            }

            if ($row['status'] == 0) {
                $this->error('该奖品已撤销');
            }

            \app\common\library\LuckyDraw::sendPrize($id, $row['user_id'], $row['prize_type'], $row['prize_value']);
            $this->success('奖品发放成功');
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 批量发放奖品
     * @throws Throwable
     */
    public function batchSendPrize(): void
    {
        try {
            $ids = $this->request->post('ids');
            if (!$ids) {
                $this->error('未选择记录');
            }

            $records = $this->model->whereIn('id', $ids)->where('status', '=', 1)->select();
            $count = 0;
            $errors = [];

            foreach ($records as $record) {
                try {
                    \app\common\library\LuckyDraw::sendPrize($record['id'], $record['user_id'], $record['prize_type'], $record['prize_value']);
                    $count++;
                } catch (\Throwable $e) {
                    $errors[] = "记录ID {$record['id']}: " . $e->getMessage();
                }
            }

            $msg = "成功发放 {$count} 个奖品";
            if (!empty($errors)) {
                $msg .= "，失败 " . count($errors) . " 个：" . implode('; ', $errors);
            }
            $this->success($msg);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 撤销奖品
     * @throws Throwable
     */
    public function revokePrize(): void
    {
        try {
            $pk = $this->model->getPk();
            $id = $this->request->param($pk);
            $row = $this->model->find($id);
            if (!$row) {
                $this->error(__('Record not found'));
            }

            $row->update(['status' => 0]);
            $this->success('奖品已撤销');
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 清除全部记录
     * @throws Throwable
     */
    public function clearAll(): void
    {
        try {
            $count = $this->model->count();
            if ($count === 0) {
                $this->success('没有可清除的记录');
            }

            Db::startTrans();
            try {
                // 使用 whereRaw 删除所有记录
                $deleted = Db::name('ba_lucky_draw_record')->whereRaw('1=1')->delete();
                Db::commit();

                if ($deleted !== false && $deleted >= 0) {
                    $this->success("成功清除 {$count} 条抽奖记录");
                } else {
                    Db::rollback();
                    $this->error('清除失败');
                }
            } catch (Throwable $e) {
                Db::rollback();
                throw $e;
            }
        } catch (Throwable $e) {
            $this->error('清除失败：' . $e->getMessage());
        }
    }

    /**
     * 统计信息
     * @throws Throwable
     */
    public function statistics(): void
    {
        try {
            // 总抽奖次数
            $totalDraw = $this->model->count();

            // 已发放奖品数
            $sendCount = $this->model->where('status', 2)->count();

            // 待发放奖品数
            $pendingCount = $this->model->where('status', 1)->count();

            // 已撤销奖品数
            $revokeCount = $this->model->where('status', 0)->count();

            // 各类型奖品统计
            $typeStats = Db::name('lucky_draw_record')
                ->field('prize_type, COUNT(*) as count, SUM(prize_value) as total_value')
                ->groupBy('prize_type')
                ->select();

            // 获奖排行
            $topWinners = Db::name('lucky_draw_record')
                ->field('user_id, COUNT(*) as draw_count')
                ->where('status', 'in', [1, 2])
                ->groupBy('user_id')
                ->orderBy('draw_count', 'desc')
                ->limit(10)
                ->select();

            $this->success('', [
                'total_draw' => $totalDraw,
                'send_count' => $sendCount,
                'pending_count' => $pendingCount,
                'revoke_count' => $revokeCount,
                'type_stats' => $typeStats,
                'top_winners' => $topWinners
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }
}

