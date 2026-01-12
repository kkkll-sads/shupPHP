<?php

namespace app\admin\controller\content;

use app\common\controller\Backend;
use think\facade\Db;

/**
 * 活动奖励配置
 */
class ActivityRewardConfig extends Backend
{
    /**
     * 无需登录的方法
     */
    protected array $noNeedLogin = [];

    /**
     * 查看配置列表
     */
    public function index(): void
    {
        $configs = Db::name('config')
            ->where('group', 'activity_reward')
            ->order('weigh', 'desc')
            ->select()
            ->toArray();
        
        $this->success('', [
            'list' => $configs,
            'total' => count($configs),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 保存配置
     */
    public function save(): void
    {
        $data = $this->request->post('data', []);
        
        if (empty($data)) {
            $this->error('请提交配置数据');
        }
        
        Db::startTrans();
        try {
            foreach ($data as $name => $value) {
                Db::name('config')
                    ->where('name', $name)
                    ->where('group', 'activity_reward')
                    ->update(['value' => $value]);
            }
            
            // 清理配置缓存
             cache('config', null);
            
            Db::commit();
            $this->success('保存成功');
        } catch (\think\exception\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Db::rollback();
            \think\facade\Log::error('ActivityRewardConfig save error: ' . $e->getMessage() . ' File: ' . $e->getFile() . ' Line: ' . $e->getLine());
            $this->error('保存失败: ' . $e->getMessage());
        }
    }
}
