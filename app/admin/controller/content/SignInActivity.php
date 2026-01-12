<?php

namespace app\admin\controller\content;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\SignInActivity as SignInActivityModel;
use think\exception\HttpResponseException;

/**
 * 签到活动管理
 */
class SignInActivity extends Backend
{
    /**
     * @var SignInActivityModel
     */
    protected object $model;

    protected string|array $preExcludeFields = ['create_time', 'update_time'];

    protected string|array $quickSearchField = ['name'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new SignInActivityModel();
    }

    /**
     * 添加活动
     * @throws Throwable
     */
    public function add(): void
    {
        if ($this->request->isPost()) {
            $data = $this->excludeFields($this->request->post());
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

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
            } else {
                $this->error(__('No rows were added'));
            }
        }

        $this->success('', [
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 编辑活动
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk, '');

        if (!$id) {
            $this->error(__('Parameter %s can not be empty', [$pk]));
        }

        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        if ($this->request->isPost()) {
            $data = $this->excludeFields($this->request->post());
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

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
                $this->success(__('Update successfully'));
            } else {
                $this->error(__('No rows were updated'));
            }
        }

        $this->success('', [
            'row' => $row,
            'remark' => get_route_remark(),
        ]);
    }

}

