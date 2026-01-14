<?php

namespace app\admin\controller\collection;

use Throwable;
use app\common\controller\Backend;
use think\facade\Db;
use think\facade\Log;

/**
 * 用户藏品管理
 * 用于查询和管理用户持有的藏品
 */
class UserCollection extends Backend
{
    protected string|array $quickSearchField = ['mobile', 'nickname', 'item_title'];

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * 用户藏品列表
     * 支持按手机号、昵称、藏品名称搜索
     * @throws Throwable
     */
    public function index(): void
    {
        $mobile = $this->request->param('mobile', '');
        $userId = $this->request->param('user_id/d', 0);
        $itemId = $this->request->param('item_id/d', 0);
        $consignmentStatus = $this->request->param('consignment_status', '');
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 20);
        $keyword = $this->request->param('quick_search', '');

        $query = Db::name('user_collection')
            ->alias('uc')
            ->leftJoin('user u', 'uc.user_id = u.id')
            ->leftJoin('collection_item ci', 'uc.item_id = ci.id')
            ->leftJoin('collection_session cs', 'ci.session_id = cs.id');

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

        // 寄售状态筛选
        if ($consignmentStatus !== '') {
            $query->where('uc.consignment_status', (int)$consignmentStatus);
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
                'uc.delivery_status',
                'uc.consignment_status',
                'uc.free_consign_attempts',
                'uc.is_old_asset_package',
                'uc.create_time',
                'ci.price as current_price',
                'ci.issue_price',
                'ci.asset_code',
                'ci.tx_hash',
                'ci.zone_id',
                'uc.mining_status',
                'uc.mining_start_time',
                'cs.title as session_title',
                'cs.id as session_id',
            ])
            ->order('uc.id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 格式化数据
        $deliveryStatusMap = [0 => '已交付', 1 => '待交付'];
        $consignmentStatusMap = [0 => '未寄售', 1 => '寄售中', 2 => '已售出'];

        foreach ($list as &$row) {
            $row['buy_time_text'] = $row['buy_time'] ? date('Y-m-d H:i:s', $row['buy_time']) : '';
            $row['create_time_text'] = $row['create_time'] ? date('Y-m-d H:i:s', $row['create_time']) : '';
            $row['delivery_status_text'] = $deliveryStatusMap[$row['delivery_status']] ?? '未知';
            $row['consignment_status_text'] = $consignmentStatusMap[$row['consignment_status']] ?? '未知';
            $row['is_old_asset_package_text'] = $row['is_old_asset_package'] == 1 ? '是' : '否';
            $row['item_image'] = $row['item_image'] ? full_url($row['item_image'], false) : '';
            $row['mining_status_text'] = $row['mining_status'] == 1 ? '矿机运行中' : '未转为矿机';
            $row['mining_start_time_text'] = $row['mining_start_time'] ? date('Y-m-d H:i:s', $row['mining_start_time']) : '';
            $row['buy_price'] = (float)$row['buy_price'];
            $row['current_price'] = (float)($row['current_price'] ?? 0);
            $row['issue_price'] = (float)($row['issue_price'] ?? 0);
            
            // 计算增值
            if ($row['buy_price'] > 0 && $row['current_price'] > 0) {
                $row['appreciation'] = round($row['current_price'] - $row['buy_price'], 2);
                $row['appreciation_rate'] = round(($row['current_price'] - $row['buy_price']) / $row['buy_price'] * 100, 2) . '%';
            } else {
                $row['appreciation'] = 0;
                $row['appreciation_rate'] = '0%';
            }
        }

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 查看用户藏品详情
     * @throws Throwable
     */
    public function detail(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('藏品ID不能为空');
        }

        $row = Db::name('user_collection')
            ->alias('uc')
            ->leftJoin('user u', 'uc.user_id = u.id')
            ->leftJoin('collection_item ci', 'uc.item_id = ci.id')
            ->leftJoin('collection_session cs', 'ci.session_id = cs.id')
            ->leftJoin('collection_order co', 'uc.order_id = co.id')
            ->where('uc.id', $id)
            ->field([
                'uc.*',
                'u.mobile',
                'u.nickname',
                'u.avatar',
                'u.username',
                'ci.price as current_price',
                'ci.issue_price',
                'ci.asset_code',
                'ci.tx_hash',
                'ci.zone_id',
                'ci.package_name',
                'uc.mining_status',
                'uc.mining_start_time', 
                'uc.last_dividend_time',
                'cs.title as session_title',
                'cs.id as session_id',
                'co.order_no',
                'co.pay_type',
                'co.status as order_status',
            ])
            ->find();

        if (!$row) {
            $this->error('藏品记录不存在');
        }

        // 格式化
        $row['buy_time_text'] = $row['buy_time'] ? date('Y-m-d H:i:s', $row['buy_time']) : '';
        $row['create_time_text'] = $row['create_time'] ? date('Y-m-d H:i:s', $row['create_time']) : '';
        $row['update_time_text'] = $row['update_time'] ? date('Y-m-d H:i:s', $row['update_time']) : '';
        $row['mining_start_time_text'] = $row['mining_start_time'] ? date('Y-m-d H:i:s', $row['mining_start_time']) : '';
        $row['last_dividend_time_text'] = $row['last_dividend_time'] ? date('Y-m-d H:i:s', $row['last_dividend_time']) : '';
        $row['image'] = $row['image'] ? full_url($row['image'], false) : '';
        $row['price'] = (float)$row['price'];
        $row['current_price'] = (float)($row['current_price'] ?? 0);
        $row['issue_price'] = (float)($row['issue_price'] ?? 0);

        // 查询寄售记录
        $consignments = Db::name('collection_consignment')
            ->where('user_collection_id', $id)
            ->order('id desc')
            ->select()
            ->toArray();

        $statusMap = [0 => '已取消', 1 => '寄售中', 2 => '已售出', 3 => '已下架'];
        foreach ($consignments as &$c) {
            $c['status_text'] = $statusMap[$c['status']] ?? '未知';
            $c['create_time_text'] = $c['create_time'] ? date('Y-m-d H:i:s', $c['create_time']) : '';
            $c['update_time_text'] = $c['update_time'] ? date('Y-m-d H:i:s', $c['update_time']) : '';
            $c['price'] = (float)$c['price'];
        }

        $this->success('', [
            'row' => $row,
            'consignments' => $consignments,
        ]);
    }

    /**
     * 按用户统计藏品
     * @throws Throwable
     */
    public function userStats(): void
    {
        $mobile = $this->request->param('mobile', '');
        $userId = $this->request->param('user_id/d', 0);

        if (!$mobile && !$userId) {
            $this->error('请提供手机号或用户ID');
        }

        // 获取用户信息
        $userQuery = Db::name('user');
        if ($mobile) {
            $userQuery->where('mobile', $mobile);
        } else {
            $userQuery->where('id', $userId);
        }
        $user = $userQuery->find();

        if (!$user) {
            $this->error('用户不存在');
        }

        // 统计藏品信息
        $stats = Db::name('user_collection')
            ->where('user_id', $user['id'])
            ->field([
                'COUNT(*) as total_count',
                'SUM(price) as total_value',
                'AVG(price) as avg_price',
                'SUM(CASE WHEN consignment_status = 0 THEN 1 ELSE 0 END) as holding',
                'SUM(CASE WHEN consignment_status = 1 THEN 1 ELSE 0 END) as consigning',
                'SUM(CASE WHEN consignment_status = 2 THEN 1 ELSE 0 END) as sold',
                'SUM(CASE WHEN mining_status = 1 THEN 1 ELSE 0 END) as mining',
            ])
            ->find();

        // 获取藏品列表
        $collections = Db::name('user_collection')
            ->alias('uc')
            ->leftJoin('collection_item ci', 'uc.item_id = ci.id')
            ->leftJoin('collection_session cs', 'ci.session_id = cs.id')
            ->where('uc.user_id', $user['id'])
            ->field([
                'uc.id',
                'uc.item_id',
                'uc.title',
                'uc.image',
                'uc.price as buy_price',
                'uc.buy_time',
                'uc.consignment_status',
                'uc.delivery_status',
                'uc.free_consign_attempts',
                'ci.price as current_price',
                'ci.asset_code',
                'cs.title as session_title',
                'uc.mining_status',
                'uc.mining_start_time',
            ])
            ->order('uc.id desc')
            ->select()
            ->toArray();

        $consignmentStatusMap = [0 => '未寄售', 1 => '寄售中', 2 => '已售出'];
        foreach ($collections as &$c) {
            $c['buy_time_text'] = $c['buy_time'] ? date('Y-m-d H:i:s', $c['buy_time']) : '';
            $c['consignment_status_text'] = $consignmentStatusMap[$c['consignment_status']] ?? '未知';
            $c['image'] = $c['image'] ? full_url($c['image'], false) : '';
            $c['mining_status_text'] = $c['mining_status'] == 1 ? '矿机运行中' : '未转为矿机';
            $c['mining_start_time_text'] = $c['mining_start_time'] ? date('Y-m-d H:i:s', $c['mining_start_time']) : '';
            $c['buy_price'] = (float)$c['buy_price'];
            $c['current_price'] = (float)($c['current_price'] ?? 0);
            
            // 计算增值
            if ($c['buy_price'] > 0 && $c['current_price'] > 0) {
                $c['appreciation'] = round($c['current_price'] - $c['buy_price'], 2);
            } else {
                $c['appreciation'] = 0;
            }
        }

        $this->success('', [
            'user' => [
                'id' => $user['id'],
                'mobile' => $user['mobile'],
                'nickname' => $user['nickname'],
                'username' => $user['username'] ?? '',
                'avatar' => $user['avatar'] ? full_url($user['avatar'], false) : '',
                'create_time' => $user['create_time'] ? date('Y-m-d H:i:s', $user['create_time']) : '',
            ],
            'stats' => [
                'total_count' => (int)$stats['total_count'],
                'total_value' => round((float)($stats['total_value'] ?? 0), 2),
                'avg_price' => round((float)($stats['avg_price'] ?? 0), 2),
                'holding' => (int)$stats['holding'],
                'consigning' => (int)$stats['consigning'],
                'sold' => (int)$stats['sold'],
                'mining' => (int)$stats['mining'],
            ],
            'collections' => $collections,
        ]);
    }


    /**
     * 手动将藏品转为矿机
     * @throws Throwable
     */
    public function toMining(): void
    {
        $id = $this->request->param('user_collection_id/d', 0);
        if (!$id) {
            $this->error('参数错误');
        }

        Db::startTrans();
        try {
            // 1. 查询藏品并锁定
            $collection = Db::name('user_collection')
                ->where('id', $id)
                ->lock(true)
                ->find();

            if (!$collection) {
                Db::rollback();
                $this->error('藏品不存在');
            }

            // 2. 检查状态
            if (isset($collection['mining_status']) && $collection['mining_status'] == 1) {
                Db::rollback();
                $this->error('该藏品已经是矿机状态，无需重复操作');
            }
            if ($collection['delivery_status'] == 1) {
                Db::rollback();
                $this->error('该藏品已提货，无法转为矿机');
            }
            if ($collection['consignment_status'] == 2) {
                 Db::rollback();
                 $this->error('该藏品已售出，无法转为矿机');
            }

            $now = time();
            
            // 🔧 清理所有非已售出的寄售记录（避免重复记录）
            // 清理 status = 0(已取消), 1(寄售中), 3(已下架/流拍) 的记录
            // 保留 status = 2(已售出) 的历史记录
            Db::name('collection_consignment')
                ->where('user_collection_id', $collection['id'])
                ->whereIn('status', [0, 1, 3]) // 清理已取消、寄售中、已下架的记录
                ->update([
                    'status' => 0, // 统一标记为已取消
                    'update_time' => $now
                ]);

            // 更新用户藏品表
            Db::name('user_collection')
                ->where('id', $collection['id'])
                ->update([
                    'mining_status' => 1,
                    'mining_start_time' => $now,
                    'last_dividend_time' => 0, // 等待第一次分红
                    'consignment_status' => 0, // 确保寄售状态归零
                    'update_time' => $now
                ]);

            // 记录日志
            $adminId = $this->auth->id; // 后台管理员ID
            Db::name('user_activity_log')->insert([
                'user_id' => $collection['user_id'],
                'related_user_id' => 0, // 管理员操作
                'action_type' => 'manual_mining_admin',
                'change_field' => 'mining_status',
                'change_value' => '1',
                'before_value' => '0',
                'after_value' => '1',
                'remark' => "管理员(ID:{$adminId})将藏品转为矿机",
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
            $this->success('操作成功，藏品已转为矿机');

        } catch (\think\exception\HttpResponseException $e) {
            Db::rollback();
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            Log::error('管理员手动转矿机失败: ' . $e->getMessage());
            $this->error('操作失败: ' . $e->getMessage());
        }
    }
}
