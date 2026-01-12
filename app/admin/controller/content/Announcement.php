<?php

namespace app\admin\controller\content;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\Announcement as AnnouncementModel;

class Announcement extends Backend
{
    /**
     * @var object
     * @phpstan-var AnnouncementModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['title', 'id'];

    protected array $noNeedPermission = ['getAnnouncementList', 'index', 'edit', 'del'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new AnnouncementModel();
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

        list($where, $alias, $limit, $order) = $this->queryBuilder('sort desc,id desc');
        $res = $this->model
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

            $data = $this->excludeFields($data);

            $result = false;
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

        $this->success('', [
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
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            $data = $this->excludeFields($data);

            $result = false;
            $this->model->startTrans();
            try {
                // 模型验证
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) $validate->scene('edit');
                        $validate->check($data);
                    }
                }
                $result = $row->save($data);
                $this->model->commit();
            } catch (Throwable $e) {
                $this->model->rollback();
                $this->error($e->getMessage());
            }
            if ($result !== false) {
                $this->success(__('Updated successfully'));
            } else {
                $this->error(__('No rows were updated'));
            }
        }

        $this->success('', [
            'row'     => $row,
            'remark'  => get_route_remark(),
        ]);
    }

    /**
     * 删除
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
        $this->model->startTrans();
        try {
            foreach ($data as $v) {
                $count += $v->delete();
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

    /**
     * 获取公告列表（前端调用）
     */
    public function getAnnouncementList(): void
    {
        $where = [
            ['status', '=', '1'],
        ];

        // 时间筛选：显示当前时间范围内有效的公告
        $now = time();
        // 开始时间：为空（0, null, ''）或小于等于当前时间
        $where[] = function ($query) use ($now) {
            $query->where(function ($q) {
                $q->where('start_time', '=', 0)
                  ->whereOr('start_time', '=', null)
                  ->whereOr('start_time', '=', '');
            })->whereOr('start_time', '<=', $now);
        };
        // 结束时间：为空（0, null, ''）或大于等于当前时间
        $where[] = function ($query) use ($now) {
            $query->where(function ($q) {
                $q->where('end_time', '=', 0)
                  ->whereOr('end_time', '=', null)
                  ->whereOr('end_time', '=', '');
            })->whereOr('end_time', '>=', $now);
        };

        $list = $this->model
            ->where($where)
            ->order('sort desc, id desc')
            ->select();

        $this->success('', [
            'list' => $list,
        ]);
    }
}
