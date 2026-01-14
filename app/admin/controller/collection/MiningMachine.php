<?php

namespace app\admin\controller\collection;

use Throwable;
use app\common\controller\Backend;
use think\facade\Db;
use think\facade\Log;

/**
 * 矿机管理
 * 用于管理已转为矿机状态的藏品（mining_status=1）
 */
class MiningMachine extends Backend
{
    protected string|array $quickSearchField = ['mobile', 'nickname', 'item_title'];

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * 矿机列表
     * 只显示 mining_status=1 的藏品
     * @throws Throwable
     */
    public function index(): void
    {
        $mobile = $this->request->param('mobile', '');
        $userId = $this->request->param('user_id/d', 0);
        $itemId = $this->request->param('item_id/d', 0);
        $sessionId = $this->request->param('session_id/d', 0);
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 20);
        $keyword = $this->request->param('quick_search', '');

        $query = Db::name('user_collection')
            ->alias('uc')
            ->leftJoin('user u', 'uc.user_id = u.id')
            ->leftJoin('collection_item ci', 'uc.item_id = ci.id')
            ->leftJoin('collection_session cs', 'ci.session_id = cs.id')
            ->where('uc.mining_status', 1); // 只查询矿机状态

        // 手机号精确查询
        if ($mobile) {
            $query->where('u.mobile', $mobile);
        }

        // 用户ID精确查询
        if ($userId > 0) {
            $query->where('uc.user_id', $userId);
        }

        // 藏品ID精确查询
        if ($itemId > 0) {
            $query->where('uc.item_id', $itemId);
        }

        // 专场ID精确查询
        if ($sessionId > 0) {
            $query->where('ci.session_id', $sessionId);
        }

        // 关键词搜索（手机号、昵称、藏品名称）
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('u.mobile', 'like', "%{$keyword}%")
                  ->whereOr('u.nickname', 'like', "%{$keyword}%")
                  ->whereOr('uc.title', 'like', "%{$keyword}%")
                  ->whereOr('ci.asset_code', 'like', "%{$keyword}%");
            });
        }

        // 获取总数
        $total = $query->count();

        // 获取列表
        $list = $query
            ->field([
                'uc.id',
                'uc.user_id',
                'u.mobile',
                'u.nickname',
                'u.avatar',
                'uc.item_id',
                'uc.title as item_title',
                'uc.image as item_image',
                'uc.price as buy_price',
                'uc.order_id',
                'uc.buy_time',
                'uc.mining_status',
                'uc.mining_start_time',
                'uc.last_dividend_time',
                'uc.create_time',
                'ci.price as current_price',
                'ci.issue_price',
                'ci.asset_code',
                'ci.zone_id',
                'cs.title as session_title',
                'cs.id as session_id',
            ])
            ->order('uc.mining_start_time desc, uc.id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 格式化数据
        foreach ($list as &$row) {
            $row['buy_time_text'] = $row['buy_time'] ? date('Y-m-d H:i:s', $row['buy_time']) : '';
            $row['create_time_text'] = $row['create_time'] ? date('Y-m-d H:i:s', $row['create_time']) : '';
            $row['mining_start_time_text'] = $row['mining_start_time'] ? date('Y-m-d H:i:s', $row['mining_start_time']) : '';
            $row['last_dividend_time_text'] = $row['last_dividend_time'] ? date('Y-m-d H:i:s', $row['last_dividend_time']) : '';
            $row['item_image'] = $row['item_image'] ? full_url($row['item_image'], false) : '';
            $row['buy_price'] = (float)$row['buy_price'];
            $row['current_price'] = (float)($row['current_price'] ?? 0);
            $row['issue_price'] = (float)($row['issue_price'] ?? 0);
            
            // 计算运行天数
            if ($row['mining_start_time']) {
                $row['mining_days'] = max(0, floor((time() - $row['mining_start_time']) / 86400));
            } else {
                $row['mining_days'] = 0;
            }
        }

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 矿机统计数据
     * @throws Throwable
     */
    public function statistics(): void
    {
        // 统计矿机总数
        $totalCount = Db::name('user_collection')
            ->where('mining_status', 1)
            ->count();

        // 统计矿机总价值
        $totalValue = Db::name('user_collection')
            ->where('mining_status', 1)
            ->sum('price');

        // 统计今日新增矿机
        $todayStart = strtotime('today');
        $todayCount = Db::name('user_collection')
            ->where('mining_status', 1)
            ->where('mining_start_time', '>=', $todayStart)
            ->count();

        // 统计涉及用户数
        $userCount = Db::name('user_collection')
            ->where('mining_status', 1)
            ->group('user_id')
            ->count();

        // ========== 分红统计 ==========
        // 今日分红总额（从user_activity_log表统计）
        $todayDividend = Db::name('user_activity_log')
            ->where('action_type', 'mining_dividend')
            ->where('create_time', '>=', $todayStart)
            ->field('SUM(JSON_UNQUOTE(JSON_EXTRACT(extra, "$.dividend_balance"))) as balance, SUM(JSON_UNQUOTE(JSON_EXTRACT(extra, "$.dividend_score"))) as score')
            ->find();

        // 累计分红总额
        $totalDividend = Db::name('user_activity_log')
            ->where('action_type', 'mining_dividend')
            ->field('SUM(JSON_UNQUOTE(JSON_EXTRACT(extra, "$.dividend_balance"))) as balance, SUM(JSON_UNQUOTE(JSON_EXTRACT(extra, "$.dividend_score"))) as score')
            ->find();

        // 最近7天分红趋势
        $dividendTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStart = strtotime("-{$i} days 00:00:00");
            $dayEnd = strtotime("-{$i} days 23:59:59");
            $dayLabel = date('m-d', $dayStart);
            
            $dayDividend = Db::name('user_activity_log')
                ->where('action_type', 'mining_dividend')
                ->where('create_time', '>=', $dayStart)
                ->where('create_time', '<=', $dayEnd)
                ->field('SUM(JSON_UNQUOTE(JSON_EXTRACT(extra, "$.dividend_balance"))) as balance, SUM(JSON_UNQUOTE(JSON_EXTRACT(extra, "$.dividend_score"))) as score, COUNT(*) as count')
                ->find();
            
            $dividendTrend[] = [
                'date' => $dayLabel,
                'balance' => round((float)($dayDividend['balance'] ?? 0), 2),
                'score' => (int)($dayDividend['score'] ?? 0),
                'count' => (int)($dayDividend['count'] ?? 0),
            ];
        }

        // 按专场分组统计
        $sessionStats = Db::name('user_collection')
            ->alias('uc')
            ->leftJoin('collection_item ci', 'uc.item_id = ci.id')
            ->leftJoin('collection_session cs', 'ci.session_id = cs.id')
            ->where('uc.mining_status', 1)
            ->field([
                'cs.id as session_id',
                'cs.title as session_title',
                'COUNT(*) as count',
                'SUM(uc.price) as total_value',
            ])
            ->group('cs.id')
            ->order('count desc')
            ->limit(10)
            ->select()
            ->toArray();

        $this->success('', [
            'total_count' => (int)$totalCount,
            'total_value' => round((float)$totalValue, 2),
            'today_count' => (int)$todayCount,
            'user_count' => (int)$userCount,
            'session_stats' => $sessionStats,
            // 分红统计
            'today_dividend_balance' => round((float)($todayDividend['balance'] ?? 0), 2),
            'today_dividend_score' => (int)($todayDividend['score'] ?? 0),
            'total_dividend_balance' => round((float)($totalDividend['balance'] ?? 0), 2),
            'total_dividend_score' => (int)($totalDividend['score'] ?? 0),
            'dividend_trend' => $dividendTrend,
        ]);
    }

    /**
     * 取消矿机状态
     * @throws Throwable
     */
    public function cancelMining(): void
    {
        $id = $this->request->param('user_collection_id/d', 0);
        if (!$id) {
            $this->error('参数错误');
        }

        Db::startTrans();
        try {
            // 查询藏品并锁定
            $collection = Db::name('user_collection')
                ->where('id', $id)
                ->lock(true)
                ->find();

            if (!$collection) {
                Db::rollback();
                $this->error('藏品不存在');
            }

            if ($collection['mining_status'] != 1) {
                Db::rollback();
                $this->error('该藏品不是矿机状态');
            }

            $now = time();

            // 更新用户藏品表
            Db::name('user_collection')
                ->where('id', $collection['id'])
                ->update([
                    'mining_status' => 0,
                    'update_time' => $now
                ]);

            // 记录日志
            $adminId = $this->auth->id;
            Db::name('user_activity_log')->insert([
                'user_id' => $collection['user_id'],
                'related_user_id' => 0,
                'action_type' => 'cancel_mining_admin',
                'change_field' => 'mining_status',
                'change_value' => '0',
                'before_value' => '1',
                'after_value' => '0',
                'remark' => "管理员(ID:{$adminId})取消矿机状态",
                'extra' => json_encode([
                    'admin_id' => $adminId,
                    'user_collection_id' => $collection['id'],
                    'item_id' => $collection['item_id'],
                    'title' => $collection['title'] ?? '',
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
                'update_time' => $now,
            ]);

            Db::commit();
            $this->success('操作成功，已取消矿机状态');

        } catch (\think\exception\HttpResponseException $e) {
            Db::rollback();
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            Log::error('管理员取消矿机失败: ' . $e->getMessage());
            $this->error('操作失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量取消矿机状态
     * @throws Throwable
     */
    public function batchCancelMining(): void
    {
        $ids = $this->request->param('ids/a', []);
        if (empty($ids)) {
            $this->error('请选择要操作的记录');
        }

        Db::startTrans();
        try {
            $now = time();
            $adminId = $this->auth->id;
            $successCount = 0;

            foreach ($ids as $id) {
                $collection = Db::name('user_collection')
                    ->where('id', $id)
                    ->where('mining_status', 1)
                    ->lock(true)
                    ->find();

                if (!$collection) {
                    continue;
                }

                // 更新状态
                Db::name('user_collection')
                    ->where('id', $collection['id'])
                    ->update([
                        'mining_status' => 0,
                        'update_time' => $now
                    ]);

                // 记录日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $collection['user_id'],
                    'related_user_id' => 0,
                    'action_type' => 'cancel_mining_admin',
                    'change_field' => 'mining_status',
                    'change_value' => '0',
                    'before_value' => '1',
                    'after_value' => '0',
                    'remark' => "管理员(ID:{$adminId})批量取消矿机状态",
                    'extra' => json_encode([
                        'admin_id' => $adminId,
                        'user_collection_id' => $collection['id'],
                        'item_id' => $collection['item_id'],
                        'title' => $collection['title'] ?? '',
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                $successCount++;
            }

            Db::commit();
            $this->success("操作成功，已取消 {$successCount} 条矿机状态");

        } catch (Throwable $e) {
            Db::rollback();
            Log::error('批量取消矿机失败: ' . $e->getMessage());
            $this->error('操作失败: ' . $e->getMessage());
        }
    }
}
