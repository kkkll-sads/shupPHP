<?php

namespace app\admin\controller\content;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\LuckyDrawPrize as LuckyDrawPrizeModel;
use think\exception\HttpResponseException;

class LuckyDrawPrize extends Backend
{
    /**
     * @var object
     * @phpstan-var LuckyDrawPrizeModel
     */
    protected object $model;

    protected string|array $preExcludeFields = [];
    protected string|array $quickSearchField = ['name', 'id'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new LuckyDrawPrizeModel();
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

        $this->success('', [
            'list'   => $res->items(),
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 新增/编辑
     * @throws Throwable
     */
    public function add(): void
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            
            try {
                $validate = str_replace("\\model\\", "\\validate\\", $this->model::class);
                if (class_exists($validate)) {
                    $validate = new $validate();
                    if (!$validate->check($data)) {
                        $this->error($validate->getError());
                    }
                }

                if (!isset($data['id'])) {
                    $this->model->save($data);
                } else {
                    $this->model->update($data);
                }

                $this->success('', [
                    'id' => $this->model->id ?? $data['id']
                ]);
            } catch (HttpResponseException $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->error($e->getMessage());
            }
        } else {
            $pk = $this->model->getPk();
            $id = $this->request->param($pk);
            $row = [];
            if ($id) {
                $row = $this->model->find($id);
                if (!$row) {
                    $this->error(__('Record not found'));
                }
                $row = $row->toArray();
            }

            $this->success('', [
                'row' => $row
            ]);
        }
    }

    /**
     * 编辑
     * @throws Throwable
     */
    public function edit(): void
    {
        $this->add();
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
     * 重置奖品计数
     * @throws Throwable
     */
    public function resetCount(): void
    {
        try {
            \app\common\library\LuckyDraw::resetPrizesDailyCount();
            $this->success('奖品计数已重置');
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }
}

