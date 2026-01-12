<?php

namespace app\admin\controller\content;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\Artist as ArtistModel;
use app\admin\model\ArtistWork as ArtistWorkModel;

class Artist extends Backend
{
    /**
     * @var object
     * @phpstan-var ArtistModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['name', 'id'];

    protected array $withJoinTable = [];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new ArtistModel();
    }

    /**
     * 艺术家列表
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
            'list'   => $res->items(),
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 添加艺术家
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
     * 编辑艺术家
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
            } else {
                $this->error(__('No rows were updated'));
            }
        }

        $this->success('', [
            'row'    => $row,
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 删除艺术家
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
                // 级联删除作品由数据库外键完成
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
     * 艺术家详情（包含作品列表）
     * @throws Throwable
     */
    public function read(): void
    {
        $pk = $this->model->getPk();
        $id = (int)$this->request->param($pk);
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        $works = ArtistWorkModel::where('artist_id', $id)
            ->order('sort desc,id desc')
            ->select()
            ->toArray();

        $this->success('', [
            'row'   => $row,
            'works' => $works,
        ]);
    }

    /**
     * 为艺术家添加作品
     * @throws Throwable
     */
    public function addWork(): void
    {
        $artistId = (int)$this->request->param('artist_id');
        if (!$artistId || !$this->model->find($artistId)) {
            $this->error('艺术家不存在');
        }

        if ($this->request->isPost()) {
            $data = $this->request->post();
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }
            $data['artist_id'] = $artistId;

            $workModel = new ArtistWorkModel();
            $data      = $this->excludeFields($data, true);

            $result = false;
            $workModel->startTrans();
            try {
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($workModel));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) {
                            $validate->scene('add');
                        }
                        $validate->check($data);
                    }
                }
                $result = $workModel->save($data);
                $workModel->commit();
            } catch (Throwable $e) {
                $workModel->rollback();
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
     * 删除作品
     * @throws Throwable
     */
    public function delWork(): void
    {
        $ids = $this->request->param('ids/a', []);
        if (!$ids) {
            $this->error(__('Parameter %s can not be empty', ['ids']));
        }

        $model = new ArtistWorkModel();
        $data  = $model->whereIn($model->getPk(), $ids)->select();

        $count = 0;
        $model->startTrans();
        try {
            foreach ($data as $v) {
                $count += $v->delete();
            }
            $model->commit();
        } catch (Throwable $e) {
            $model->rollback();
            $this->error($e->getMessage());
        }
        if ($count) {
            $this->success(__('Deleted successfully'));
        } else {
            $this->error(__('No rows were deleted'));
        }
    }
}


