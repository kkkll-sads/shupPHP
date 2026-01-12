<?php

namespace app\admin\controller;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\TradingTimeConfig as TradingTimeConfigModel;

class TradingTimeConfig extends Backend
{
    /**
     * @var TradingTimeConfigModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['name', 'id'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new TradingTimeConfigModel();
    }

    /**
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        [$where, $alias, $limit, $order] = $this->queryBuilder('sort desc,id desc');

        $res = $this->model
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        $this->success('', [
            'list' => $res->items(),
            'total' => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function add(): void
    {
        if ($this->request->isPost()) {
            $data = $this->excludeFields($this->request->post());
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            unset($data['create_time'], $data['update_time']);

            $result = false;
            $this->model->startTrans();
            try {
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) {
                            $validate->scene('add');
                        }
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
            }
            $this->error(__('No rows were added'));
        }

        $this->success('', [
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($this->request->isPost()) {
            $data = $this->excludeFields($this->request->post());
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            unset($data['create_time'], $data['update_time']);

            $result = false;
            $this->model->startTrans();
            try {
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) {
                            $validate->scene('edit');
                        }
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
            }
            $this->error(__('No rows were updated'));
        }

        $this->success('', [
            'row' => $row,
            'remark' => get_route_remark(),
        ]);
    }

    /**
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
        } catch (Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }

        if ($count) {
            $this->success(__('Deleted successfully'));
        }
        $this->error(__('No rows were deleted'));
    }
}

