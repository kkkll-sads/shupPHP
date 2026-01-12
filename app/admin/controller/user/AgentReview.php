<?php

namespace app\admin\controller\user;

use Throwable;
use think\facade\Db;
use app\common\controller\Backend;
use think\exception\HttpResponseException;

class AgentReview extends Backend
{
    /**
     * 查看代理商审核列表
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }
        
        // 手动构建查询条件（参考银行卡管理）
        $quickSearch = $this->request->get('quickSearch/s', '');
        $limit       = $this->request->get('limit/d', 10);
        $search      = $this->request->get('search/a', []);

        $where = [];

        // 快速搜索：ID / 用户名 / 企业名称 / 法人
        if ($quickSearch) {
            $searchValue = '%' . str_replace('%', '\%', $quickSearch) . '%';
            $where[] = function ($query) use ($searchValue) {
                $query->where('ar.id', 'like', $searchValue)
                    ->whereOr('ar.company_name', 'like', $searchValue)
                    ->whereOr('ar.legal_person', 'like', $searchValue)
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
                // 默认使用 ar 表别名，用户字段走 u
                if (in_array($fieldName, ['username', 'nickname', 'mobile'])) {
                    $fieldName = 'u.' . $fieldName;
                } else {
                    $fieldName = 'ar.' . $fieldName;
                }
            }

            $operator = $field['operator'];
            $val      = $field['val'];

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
        $orderField = $this->request->get("order/s", 'ar.id desc');
        if ($orderField) {
            $orderParts = explode(',', $orderField);
            $order      = [];
            foreach ($orderParts as $part) {
                $part = trim($part);
                if (str_contains($part, ' ')) {
                    $parts            = explode(' ', $part);
                    $order[$parts[0]] = $parts[1] ?? 'asc';
                } else {
                    $order[$part] = 'desc';
                }
            }
        } else {
            $order = ['ar.id' => 'desc'];
        }

        $res = Db::name('agent_review')
            ->alias('ar')
            ->leftJoin('ba_user u', 'ar.user_id = u.id')
            ->field('ar.*, u.username, u.nickname, u.mobile')
            ->where($where)
            ->order($order)
            ->paginate($limit);

        $list = $res->items();

        $statusMap = [
            0 => '待审核',
            1 => '已通过',
            2 => '已拒绝',
        ];
        $subjectTypeMap = [
            1 => '个体户',
            2 => '企业法人',
        ];

        foreach ($list as &$item) {
            $item['status_text']       = $statusMap[(int)$item['status']] ?? '未知';
            $item['subject_type_text'] = $subjectTypeMap[(int)$item['subject_type']] ?? '未知';
            $item['audit_time_text']   = $item['audit_time'] ? date('Y-m-d H:i:s', (int)$item['audit_time']) : '';
        }

        $this->success('', [
            'list'   => $list,
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

        $row = Db::name('agent_review')
            ->alias('ar')
            ->leftJoin('ba_user u', 'ar.user_id = u.id')
            ->field('ar.*, u.username, u.nickname, u.mobile')
            ->where('ar.id', $id)
            ->find();

        if (!$row) {
            $this->error(__('Record not found'));
        }

        $statusMap = [
            0 => '待审核',
            1 => '已通过',
            2 => '已拒绝',
        ];
        $subjectTypeMap = [
            1 => '个体户',
            2 => '企业法人',
        ];

        $row['status_text']       = $statusMap[(int)$row['status']] ?? '未知';
        $row['subject_type_text'] = $subjectTypeMap[(int)$row['subject_type']] ?? '未知';
        $row['audit_time_text']   = $row['audit_time'] ? date('Y-m-d H:i:s', (int)$row['audit_time']) : '';

        $this->success('', [
            'row' => $row,
        ]);
    }

    /**
     * 审核通过
     * @throws Throwable
     */
    public function approve(): void
    {
        $id = $this->request->post('id/d', 0);
        if (!$id) {
            $id = $this->request->param('id/d', 0);
        }
        if (!$id) {
            $this->error('ID不能为空');
        }

        $row = Db::name('agent_review')->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }
        if ((int)$row['status'] !== 0) {
            $this->error('仅待审核状态可操作');
        }

        $remark  = (string)$this->request->post('audit_remark', '');
        $adminId = $this->auth->id;
        $now     = time();

        Db::startTrans();
        try {
            Db::name('agent_review')
                ->where('id', $id)
                ->update([
                    'status'         => 1,
                    'audit_time'     => $now,
                    'audit_admin_id' => $adminId,
                    'audit_remark'   => $remark,
                    'update_time'    => $now,
                ]);

            Db::commit();
            // 注意：success 会抛出 HttpResponseException，这里不要被后续 Throwable 捕获
            $this->success('审核通过成功');
        } catch (HttpResponseException $e) {
            // 正常响应，直接抛出，不回滚事务
            throw $e;
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
        $id = $this->request->post('id/d', 0);
        if (!$id) {
            $id = $this->request->param('id/d', 0);
        }
        if (!$id) {
            $this->error('ID不能为空');
        }

        $row = Db::name('agent_review')->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }
        if ((int)$row['status'] !== 0) {
            $this->error('仅待审核状态可操作');
        }

        $remark = trim((string)$this->request->post('audit_remark', ''));
        if ($remark === '') {
            $this->error('拒绝原因不能为空');
        }

        $adminId = $this->auth->id;
        $now     = time();

        Db::startTrans();
        try {
            Db::name('agent_review')
                ->where('id', $id)
                ->update([
                    'status'         => 2,
                    'audit_time'     => $now,
                    'audit_admin_id' => $adminId,
                    'audit_remark'   => $remark,
                    'update_time'    => $now,
                ]);

            Db::commit();
            // 同样避免 success 抛出的 HttpResponseException 被误当成异常处理
            $this->success('审核拒绝成功');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    /**
     * 删除代理商审核记录
     * @throws Throwable
     */
    public function del(): void
    {
        $ids = $this->request->param('ids/a', []);
        if (empty($ids)) {
            // 兼容单个 id 传参
            $id = $this->request->param('id/d', 0);
            if ($id) {
                $ids = [$id];
            }
        }

        if (empty($ids)) {
            $this->error('未指定要删除的记录');
        }

        Db::startTrans();
        try {
            $count = Db::name('agent_review')
                ->whereIn('id', $ids)
                ->delete();

            Db::commit();

            if ($count > 0) {
                $this->success(__('Deleted successfully'));
            } else {
                $this->error(__('No rows were deleted'));
            }
        } catch (Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }
}

