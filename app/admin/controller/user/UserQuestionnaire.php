<?php

namespace app\admin\controller\user;

use app\admin\model\UserQuestionnaire as UserQuestionnaireModel;
use app\common\controller\Backend;
use think\facade\Db;
use think\facade\Config;

/**
 * 用户问卷管理
 */
class UserQuestionnaire extends Backend
{
    protected object $model;

    protected array $withJoinTable = ['user'];

    protected string|array $preExcludeFields = ['create_time', 'update_time'];

    protected string|array $quickSearchField = ['user.username', 'user.nickname', 'user.mobile', 'title'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new UserQuestionnaireModel();
    }

    /**
     * 查看
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $res = $this->model
            ->withJoin($this->withJoinTable, $this->withJoinType)
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        $list = $res->items();
        foreach ($list as &$item) {
            // 添加状态文字
            $item['status_text'] = match((int)$item['status']) {
                0 => '待审核',
                1 => '已采纳',
                2 => '已拒绝',
                default => '未知'
            };
        }
        unset($item);

        $this->success('', [
            'list'   => $list,
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 采纳问卷
     */
    public function adopt(): void
    {
        $ids = $this->request->param('ids');
        $remark = $this->request->param('admin_remark', '');
        
        if (!$ids) {
            $this->error('请选择要采纳的问卷');
        }
        
        $questionnaire = $this->model->find($ids);
        if (!$questionnaire) {
            $this->error('问卷不存在');
        }
        
        if ($questionnaire->status != UserQuestionnaireModel::STATUS_PENDING) {
            $this->error('该问卷已被处理');
        }
        
        // 获取奖励算力配置
        $rewardPower = Db::name('config')
            ->where('name', 'questionnaire_reward_power')
            ->value('value') ?: 30;
        
        Db::startTrans();
        try {
            // 更新问卷状态
            $questionnaire->status = UserQuestionnaireModel::STATUS_ADOPTED;
            $questionnaire->reward_power = $rewardPower;
            $questionnaire->admin_remark = $remark;
            $questionnaire->save();
            
            // 给用户增加算力
            Db::name('user')
                ->where('id', $questionnaire->user_id)
                ->inc('green_power', $rewardPower)
                ->update();
            
            // 获取最新算力
            $currentPower = Db::name('user')->where('id', $questionnaire->user_id)->value('green_power');

            // 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $questionnaire->user_id,
                'related_user_id' => 0,
                'action_type' => 'questionnaire_reward',
                'change_field' => 'green_power',
                'change_value' => $rewardPower,
                'before_value' => (float)$currentPower - $rewardPower,
                'after_value' => (float)$currentPower,
                'remark' => "问卷采纳奖励: {$questionnaire->title}",
                'create_time' => time(),
                'update_time' => time(),
            ]);
            
            Db::commit();
            $this->success('采纳成功，已奖励 ' . $rewardPower . ' 算力');
        } catch (\think\exception\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Db::rollback();
            $this->error('操作失败: ' . $e->getMessage());
        }
    }

    /**
     * 拒绝问卷
     */
    public function reject(): void
    {
        $ids = $this->request->param('ids');
        $remark = $this->request->param('admin_remark', '');
        
        if (!$ids) {
            $this->error('请选择要拒绝的问卷');
        }
        
        $questionnaire = $this->model->find($ids);
        if (!$questionnaire) {
            $this->error('问卷不存在');
        }
        
        if ($questionnaire->status != UserQuestionnaireModel::STATUS_PENDING) {
            $this->error('该问卷已被处理');
        }
        
        $questionnaire->status = UserQuestionnaireModel::STATUS_REJECTED;
        $questionnaire->admin_remark = $remark;
        $questionnaire->save();
        
        $this->success('已拒绝');
    }
}
