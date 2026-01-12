<?php

namespace app\admin\controller\collection;

use Throwable;
use think\facade\Db;
use app\common\controller\Backend;
use app\admin\model\CollectionMatchingPool as CollectionMatchingPoolModel;
use think\exception\HttpResponseException;

class MatchingPool extends Backend
{
    /**
     * @var CollectionMatchingPoolModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['id'];

    protected array $withJoinTable = ['user', 'item', 'session'];

    protected string|array $defaultSortField = 'collection_matching_pool.id,desc';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new CollectionMatchingPoolModel();
    }

    /**
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        [$where, $alias, $limit, $order] = $this->queryBuilder('collection_matching_pool.id desc');

        // 使用join查询关联数据
        $res = $this->model
            ->alias($alias)
            ->leftJoin('collection_item ci', 'collection_matching_pool.item_id = ci.id')
            ->leftJoin('collection_session cs', 'collection_matching_pool.session_id = cs.id')
            ->leftJoin('user u', 'collection_matching_pool.user_id = u.id')
            ->leftJoin('collection_order co', 'collection_matching_pool.match_order_id = co.id')
            ->where($where)
            ->field([
                'collection_matching_pool.*',
                'ci.title as item_title',
                'ci.image as item_image',
                'cs.title as session_title',
                'u.nickname as user_nickname',
                'u.avatar as user_avatar',
                'co.order_no as match_order_no',
            ])
            ->order($order)
            ->paginate($limit);

        $list = $res->items();
        foreach ($list as &$item) {
            // 转换为数组以便处理
            if (is_object($item)) {
                $item = $item->toArray();
            }
            // 设置状态文本
            $statusMap = [
                'pending' => '待撮合',
                'matched' => '已撮合',
                'cancelled' => '已取消',
            ];
            $item['status_text'] = $statusMap[$item['status']] ?? '未知';
            
            // 处理图片URL
            if (!empty($item['item_image'])) {
                $item['item_image'] = full_url($item['item_image'], false);
            }
            if (!empty($item['user_avatar'])) {
                $item['user_avatar'] = full_url($item['user_avatar'], false);
            }
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
    public function detail(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('参数错误');
        }

        $row = $this->model
            ->alias('mp')
            ->leftJoin('collection_item ci', 'mp.item_id = ci.id')
            ->leftJoin('collection_session cs', 'mp.session_id = cs.id')
            ->leftJoin('user u', 'mp.user_id = u.id')
            ->leftJoin('collection_order co', 'mp.match_order_id = co.id')
            ->where('mp.id', $id)
            ->field([
                'mp.*',
                'ci.title as item_title',
                'ci.image as item_image',
                'cs.title as session_title',
                'cs.start_time as session_start_time',
                'cs.end_time as session_end_time',
                'u.nickname as user_nickname',
                'u.avatar as user_avatar',
                'co.order_no as match_order_no',
                'co.total_amount as match_order_total_amount',
            ])
            ->find();

        if (!$row) {
            $this->error('记录不存在');
        }

        // 处理数据
        $data = $row->toArray();
        $statusMap = [
            'pending' => '待撮合',
            'matched' => '已撮合',
            'cancelled' => '已取消',
        ];
        $data['status_text'] = $statusMap[$data['status']] ?? '未知';
        
        // 处理图片URL
        if (!empty($data['item_image'])) {
            $data['item_image'] = full_url($data['item_image'], false);
        }
        if (!empty($data['user_avatar'])) {
            $data['user_avatar'] = full_url($data['user_avatar'], false);
        }

        $this->success('', $data);
    }

    /**
     * 删除记录（支持批量）
     * @throws Throwable
     */
    public function del(): void
    {
        $where = [];
        $dataLimitAdminIds = $this->getDataLimitAdminIds();
        if ($dataLimitAdminIds) {
            $where[] = [$this->dataLimitField, 'in', $dataLimitAdminIds];
        }

        $ids = $this->request->param('ids/a', []);
        $where[] = [$this->model->getPk(), 'in', $ids];
        $list = $this->model->where($where)->select();

        $count = 0;
        $this->model->startTrans();
        try {
            foreach ($list as $item) {
                $count += $item->delete();
            }
            $this->model->commit();
        } catch (HttpResponseException $e) {
            // 正常的响应（success）会抛出 HttpResponseException，直接复抛以保持原始响应
            throw $e;
        } catch (Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }

        if ($count) {
            $this->success(__('Deleted successfully'));
        }
        $this->error(__('No rows were deleted'));
    }

    /**
     * 一键清除未关联用户的撮合池记录（user_id=0 或关联用户已被删除）
     * @throws Throwable
     */
    public function clearOrphans(): void
    {
        $this->model->startTrans();
        try {
            // 查找 orphan id 列表
            $rows = Db::query("SELECT mp.id FROM `ba_collection_matching_pool` mp LEFT JOIN `ba_user` u ON mp.user_id = u.id WHERE mp.user_id = 0 OR u.id IS NULL");
            $ids = array_column($rows, 'id');
            if (empty($ids)) {
                $this->model->commit();
                $this->success('', ['cleared' => 0]);
            }

            $count = $this->model->whereIn($this->model->getPk(), $ids)->delete();
            $this->model->commit();
            $this->success('', ['cleared' => $count]);
        } catch (HttpResponseException $e) {
            // 正常的 success() 会抛出 HttpResponseException，直接复抛以保持成功响应
            throw $e;
        } catch (Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }
    }
}

