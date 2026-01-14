<?php

namespace app\api\controller;

use app\common\controller\Frontend;
use think\facade\Db;
use hg\apidoc\annotation as Apidoc;
use app\admin\model\UserQuestionnaire;

#[Apidoc\Title("活动中心")]
class ActivityCenter extends Frontend
{
    protected array $noNeedLogin = ['index'];
    protected array $noNeedPermission = ['*'];

    #[
        Apidoc\Title("获取活动列表"),
        Apidoc\Tag("活动中心"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/ActivityCenter/index"),
        Apidoc\Returned("list", type:"array", desc:"活动列表", children:[
            ["name"=>"key", "type"=>"string", "desc"=>"活动标识(first_trade=首次交易, invite_reward=邀请奖励, sub_trade=下级首购, questionnaire=问卷, recharge=充值)"],
            ["name"=>"title", "type"=>"string", "desc"=>"活动标题"],
            ["name"=>"desc", "type"=>"string", "desc"=>"活动描述"],
            ["name"=>"icon", "type"=>"string", "desc"=>"图标URL"],
            ["name"=>"status", "type"=>"int", "desc"=>"状态:0=未完成/进行中, 1=已完成/今日已达上限"],
            ["name"=>"btn_text", "type"=>"string", "desc"=>"按钮文字"],
            ["name"=>"app_path", "type"=>"string", "desc"=>"前端跳转路径"],
            ["name"=>"rewards", "type"=>"array", "desc"=>"奖励内容", "children"=>[
                ["name"=>"type", "type"=>"string", "desc"=>"类型(score=消费金, power=算力)"],
                ["name"=>"value", "type"=>"float", "desc"=>"数值"],
                ["name"=>"name", "type"=>"string", "desc"=>"奖励名称"],
            ]],
        ]),
    ]
    public function index(): void
    {
        // 获取配置
        $config = Db::name('config')
            ->where('group', 'activity_reward')
            ->column('value', 'name');
            
        $list = [];
        $userId = $this->auth->id;
        $userInfo = $userId ? Db::name('user')->where('id', $userId)->find() : null;
        
        // 1. 首次交易奖励
        if (($config['first_trade_reward_score'] ?? 0) > 0 || ($config['first_trade_reward_power'] ?? 0) > 0) {
            $isDone = $userInfo && $userInfo['user_type'] > 0;
            $rewards = [];
            if (($config['first_trade_reward_score'] ?? 0) > 0) {
                $rewards[] = ['type' => 'score', 'value' => (float)$config['first_trade_reward_score'], 'name' => '消费金'];
            }
            if (($config['first_trade_reward_power'] ?? 0) > 0) {
                $rewards[] = ['type' => 'power', 'value' => (float)$config['first_trade_reward_power'], 'name' => '算力'];
            }
            
            $list[] = [
                'key' => 'first_trade',
                'title' => '首次交易奖励',
                'desc' => '完成平台任意商品首次购买，立享新人豪礼',
                'icon' => '/assets/img/activity/first_trade.png', // 需前端确认图标路径，暂用占位
                'status' => $isDone ? 1 : 0,
                'btn_text' => $isDone ? '已完成' : '去完成',
                'app_path' => '/pages/market/index',
                'rewards' => $rewards
            ];
        }

        // 2. 邀请好友奖励
        if (($config['invite_reward_power'] ?? 0) > 0) {
            // 永远是进行中
            $list[] = [
                'key' => 'invite_reward',
                'title' => '邀请好友有礼',
                'desc' => '每邀请一位好友注册并实名，即可获得算力奖励',
                'icon' => '/assets/img/activity/invite.png',
                'status' => 0, 
                'btn_text' => '去邀请',
                'app_path' => '/pages/user/poster',
                'rewards' => [
                    ['type' => 'power', 'value' => (float)$config['invite_reward_power'], 'name' => '算力/人']
                ]
            ];
        }

        // 3. 下级首购奖励
        if (($config['sub_trade_reward_score'] ?? 0) > 0 || ($config['sub_trade_reward_power'] ?? 0) > 0) {
            $rewards = [];
            if (($config['sub_trade_reward_score'] ?? 0) > 0) {
                $rewards[] = ['type' => 'score', 'value' => (float)$config['sub_trade_reward_score'], 'name' => '消费金'];
            }
            if (($config['sub_trade_reward_power'] ?? 0) > 0) {
                $rewards[] = ['type' => 'power', 'value' => (float)$config['sub_trade_reward_power'], 'name' => '算力'];
            }
            
            $list[] = [
                'key' => 'sub_trade',
                'title' => '好友首购奖励',
                'desc' => '直推下级完成首次交易，可获丰厚奖励',
                'icon' => '/assets/img/activity/sub_trade.png',
                'status' => 0,
                'btn_text' => '去邀请',
                'app_path' => '/pages/user/poster',
                'rewards' => $rewards
            ];
        }

        // 4. 每日问卷奖励
        if (($config['questionnaire_reward_power'] ?? 0) > 0) {
            // 检查今日是否已达上限 (每日3次)
            $isDone = false;
            if ($userId) {
                $todayStart = strtotime('today');
                $todayCount = Db::name('user_questionnaire')
                    ->where('user_id', $userId)
                    ->where('create_time', '>=', $todayStart)
                    ->count();
                if ($todayCount >= 3) {
                    $isDone = true;
                }
            }
            
            $list[] = [
                'key' => 'questionnaire',
                'title' => '答问卷领算力',
                'desc' => '参与每日问卷调查，通过由于获得算力奖励',
                'icon' => '/assets/img/activity/questionnaire.png',
                'status' => $isDone ? 1 : 0,
                'btn_text' => $isDone ? '今日已达上限' : '去参与',
                'app_path' => '/pages/questionnaire/index', // 假设路径
                'rewards' => [
                    ['type' => 'power', 'value' => (float)$config['questionnaire_reward_power'], 'name' => '算力/次']
                ]
            ];
        }

        // 5. 充值奖励
        if (($config['recharge_reward_power_rate'] ?? 0) > 0) {
            $list[] = [
                'key' => 'recharge',
                'title' => '充值送算力',
                'desc' => '每日充值金额，额外的 ' . $config['recharge_reward_power_rate'] . '% 算力赠送',
                'icon' => '/assets/img/activity/recharge.png',
                'status' => 0,
                'btn_text' => '去充值',
                'app_path' => '/pages/recharge/index',
                'rewards' => [
                    ['type' => 'power_rate', 'value' => (float)$config['recharge_reward_power_rate'], 'name' => '充值金额%算力']
                ]
            ];
        }

        $this->success('', ['list' => $list]);
    }
}
