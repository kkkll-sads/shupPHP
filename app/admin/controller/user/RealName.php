<?php

namespace app\admin\controller\user;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\User as UserModel;
use think\facade\Db;
use think\exception\HttpResponseException;

class RealName extends Backend
{
    /**
     * @var object
     * @phpstan-var UserModel
     */
    protected object $model;

    protected array $withJoinTable = ['userGroup'];

    // 排除字段
    protected string|array $preExcludeFields = ['last_login_time', 'login_failure', 'password', 'salt'];

    protected string|array $quickSearchField = ['username', 'nickname', 'real_name', 'id_card', 'id'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new UserModel();
    }

    /**
     * 查看实名认证列表
     * @throws Throwable
     */
    public function index(): void
    {
        list($where, $alias, $limit, $order) = $this->queryBuilder();
        
        // 默认只显示已提交实名认证的用户（待审核、已通过、已拒绝）
        // 检查是否已经有real_name_status的查询条件
        $hasRealNameStatus = false;
        foreach ($where as $condition) {
            if (is_array($condition) && isset($condition[0]) && 
                (str_contains($condition[0], 'real_name_status') || $condition[0] === 'real_name_status')) {
                $hasRealNameStatus = true;
                break;
            }
        }
        
        if (!$hasRealNameStatus) {
            $modelTable = strtolower($this->model->getTable());
            $mainTableAlias = parse_name(basename(str_replace('\\', '/', get_class($this->model)))) . '.';
            $where[] = [$mainTableAlias . 'real_name_status', '>', 0];
        }
        
        $res = $this->model
            ->withoutField('password,salt')
            ->withJoin($this->withJoinTable, $this->withJoinType)
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        $this->success('', [
            'list'   => $res->items(),
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
        $pk  = $this->model->getPk();
        $id  = $this->request->param($pk);
        $row = $this->model->withJoin($this->withJoinTable, $this->withJoinType)->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        unset($row->salt);
        $row->password = '';
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

        // 允许待审核或已拒绝状态重新审核通过
        if ($row->real_name_status != 1 && $row->real_name_status != 3) {
            $this->error('该用户状态不允许审核通过');
        }

        $remark = $this->request->post('audit_remark', '');
        $adminId = $this->auth->id;
        
        if (!$adminId) {
            $this->error('管理员ID不能为空，请重新登录');
        }

        $this->model->startTrans();
        try {
            $result = $this->model->where('id', $id)->update([
                'real_name_status' => 2, // 已通过
                'audit_time' => time(),
                'audit_admin_id' => $adminId,
                'audit_remark' => $remark,
            ]);
            
            if ($result === false) {
                $this->model->rollback();
                $this->error('审核失败：更新数据库失败');
            }
            
            $this->model->commit();
            
            // 实名认证通过后，给邀请人发放邀请奖励
            if ($row->inviter_id > 0) {
                try {
                    $listener = new \app\listener\UserRegisterSuccess();
                    $listener->handleInviteReward($row->inviter_id, $row->id);
                } catch (\Throwable $e) {
                    // 邀请奖励发放失败不影响审核结果
                    \think\facade\Log::error('实名认证通过后发放邀请奖励失败: ' . $e->getMessage());
                }
            }
            
            $this->success('审核通过成功');
        } catch (HttpResponseException $e) {
            // HttpResponseException 是 ThinkPHP 的正常响应机制，直接重新抛出
            throw $e;
        } catch (Throwable $e) {
            $this->model->rollback();
            $errorMsg = $e->getMessage() ?: '审核过程中发生未知错误：' . $e::class;
            $this->error($errorMsg);
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

        // 允许待审核或已通过状态拒绝
        if ($row->real_name_status != 1 && $row->real_name_status != 2) {
            $this->error('该用户状态不允许审核拒绝');
        }

        $remark = $this->request->post('audit_remark', '');
        if (empty($remark)) {
            $this->error('拒绝原因不能为空');
        }

        $adminId = $this->auth->id;
        
        if (!$adminId) {
            $this->error('管理员ID不能为空，请重新登录');
        }

        $this->model->startTrans();
        try {
            $result = $this->model->where('id', $id)->update([
                'real_name_status' => 3, // 已拒绝
                'audit_time' => time(),
                'audit_admin_id' => $adminId,
                'audit_remark' => $remark,
            ]);
            
            if ($result === false) {
                $this->model->rollback();
                $this->error('审核失败：更新数据库失败');
            }
            
            $this->model->commit();
            $this->success('审核拒绝成功');
        } catch (HttpResponseException $e) {
            // HttpResponseException 是 ThinkPHP 的正常响应机制，直接重新抛出
            throw $e;
        } catch (Throwable $e) {
            $this->model->rollback();
            $errorMsg = $e->getMessage() ?: '审核过程中发生未知错误：' . $e::class;
            $this->error($errorMsg);
        }
    }

    /**
     * 删除实名认证记录（清除实名信息）
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
        $data = $this->model->where($where)->select();

        $count = 0;
        $this->model->startTrans();
        try {
            foreach ($data as $v) {
                // 清除实名认证信息，将状态重置为未实名
                $result = $this->model->where('id', $v->id)->update([
                    'real_name' => '',
                    'id_card' => '',
                    'id_card_front' => '',
                    'id_card_back' => '',
                    'real_name_status' => 0, // 未实名
                    'audit_time' => 0,
                    'audit_admin_id' => 0,
                    'audit_remark' => '',
                ]);
                if ($result !== false) {
                    $count++;
                }
            }
            $this->model->commit();
        } catch (Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }
        if ($count) {
            $this->success(__('Deleted successfully'));
        } else {
            $this->error(__('No rows were deleted'));
        }
    }
}

