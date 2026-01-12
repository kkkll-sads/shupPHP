<?php

namespace app\admin\controller\security;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\LuckyDrawConfig as LuckyDrawConfigModel;

class LuckyDrawConfig extends Backend
{
    /**
     * @var object
     * @phpstan-var LuckyDrawConfigModel
     */
    protected object $model;

    protected string|array $preExcludeFields = [];
    protected string|array $quickSearchField = ['config_key', 'id'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new LuckyDrawConfigModel();
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
            
            // 验证配置值
            if (isset($data['config_value'])) {
                $configKey = $row['config_key'];
                
                // 对于数值类型的配置进行验证
                if (in_array($configKey, ['daily_draw_limit', 'draw_score_cost', 'daily_limit_reset_hour'])) {
                    if (!is_numeric($data['config_value']) || $data['config_value'] < 0) {
                        $this->error('配置值必须为非负数');
                    }
                }
                
                // 对于布尔类型的配置进行验证
                if (in_array($configKey, ['prize_send_auto'])) {
                    if (!in_array($data['config_value'], [0, 1, '0', '1'])) {
                        $this->error('配置值必须为 0 或 1');
                    }
                }
            }

            $row->update($data);
            $this->success('');
        } else {
            $row = $row->toArray();
            $this->success('', [
                'row' => $row
            ]);
        }
    }

    /**
     * 获取所有配置
     * @throws Throwable
     */
    public function getAll(): void
    {
        try {
            $configs = $this->model->select()->toArray();
            $result = [];
            
            foreach ($configs as $config) {
                $result[$config['config_key']] = $config['config_value'];
            }

            $this->success('', $result);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 更新配置
     * @throws Throwable
     */
    public function update(): void
    {
        try {
            $configKey = $this->request->post('config_key');
            $configValue = $this->request->post('config_value');
            $remark = $this->request->post('remark', '');

            if (!$configKey) {
                $this->error('配置键不能为空');
            }

            \app\common\library\LuckyDraw::setConfig($configKey, $configValue, $remark);
            $this->success('配置更新成功');
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
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
}

