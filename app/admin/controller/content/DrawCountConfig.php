<?php

namespace app\admin\controller\content;

use Throwable;
use app\common\controller\Backend;
use think\facade\Cache;
use app\admin\model\Config as ConfigModel;
use app\admin\model\DrawCountConfig as DrawCountConfigModel;
use app\admin\model\User as UserModel;
use app\admin\model\InviteRecord as InviteRecordModel;
use app\admin\library\DrawCountService;
use app\common\library\LuckyDraw;
use app\common\library\YidunOcr;
use app\common\library\SmsBao;
use app\common\library\WeiWebsSms;
use think\exception\HttpResponseException;

class DrawCountConfig extends Backend
{
    /**
     * @var object
     * @phpstan-var DrawCountConfigModel
     */
    protected object $model;

    protected string|array $preExcludeFields = [];
    protected string|array $quickSearchField = ['direct_people', 'id'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new DrawCountConfigModel();
    }

    /**
     * 列表
     * @throws Throwable
     */
    public function index(): void
    {
        $res = $this->model->order('direct_people', 'asc')->select();

        $this->success('', [
            'list'   => $res->toArray(),
            'total'  => count($res),
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

                // 检查直推人数是否已存在（编辑时除外）
                if (!isset($data['id']) || $data['id'] === null || $data['id'] === '') {
                    $exists = $this->model->where('direct_people', $data['direct_people'])->find();
                    if ($exists) {
                        $this->error('该直推人数的配置已存在');
                    }
                    $newRow = DrawCountConfigModel::create($data);
                    $this->success('', [
                        'id' => $newRow->id,
                    ]);
                } else {
                    // 编辑时检查新的直推人数是否与其他记录冲突
                    $exists = $this->model->where('direct_people', $data['direct_people'])
                        ->where('id', '<>', $data['id'])
                        ->find();
                    if ($exists) {
                        $this->error('该直推人数的配置已存在');
                    }
                    $updatedRow = $this->model::update($data);
                    $this->success('', [
                        'id' => $updatedRow->id ?? $data['id'],
                    ]);
                }
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
     * 计算用户的抽奖次数
     * @throws Throwable
     */
    public function calculateUserDrawCount(): void
    {
        try {
            $userId = $this->request->param('user_id');
            if (!$userId) {
                $this->error('用户ID不能为空');
            }

            $service = new DrawCountService();
            $directCount = $service->getDirectInviteCount($userId);
            $drawCount = $service->getDrawCountByDirectPeople($directCount);

            $this->success('', [
                'direct_count' => $directCount,
                'draw_count' => $drawCount
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 重新计算所有用户的抽奖次数
     * @throws Throwable
     */
    public function recalculateAllDrawCount(): void
    {
        try {
            $service = new DrawCountService();
            $updatedCount = $service->recalculateAllUsersDrawCount();

            $this->success("已更新 {$updatedCount} 个用户的抽奖次数");
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取配置统计信息
     * @throws Throwable
     */
    public function getStatistics(): void
    {
        try {
            $service = new DrawCountService();
            $statistics = $service->getConfigStatistics();
            
            $inviteRecord = new InviteRecordModel();
            $totalInviters = $inviteRecord->distinct('inviter_id')->count();

            $this->success('', [
                'statistics' => $statistics,
                'total_inviters' => $totalInviters
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存注册成功送奖励配置
     * @throws Throwable
     */
    public function registerRewardConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $money = (float)$this->request->post('money', 0);
                $score = (int)$this->request->post('score', 0);
                $withdrawableMoney = (float)$this->request->post('withdrawable_money', 0);
                $greenPower = (float)$this->request->post('green_power', 0);

                // 验证数据
                if ($money < 0) {
                    $this->error('可用金额不能为负数');
                }
                if ($score < 0) {
                    $this->error('消费金不能为负数');
                }
                if ($withdrawableMoney < 0) {
                    $this->error('可提现金额不能为负数');
                }
                if ($greenPower < 0) {
                    $this->error('绿色算力不能为负数');
                }

                // 保存配置到系统配置表
                $configs = [
                    'register_reward_money' => ['value' => (string)$money, 'title' => '注册奖励-可用金额（元）'],
                    'register_reward_score' => ['value' => (string)$score, 'title' => '注册奖励-消费金（积分）'],
                    'register_reward_withdraw' => ['value' => (string)$withdrawableMoney, 'title' => '注册奖励-可提现金额（元）'],
                    'register_reward_green' => ['value' => (string)$greenPower, 'title' => '注册奖励-绿色算力'],
                ];

                foreach ($configs as $name => $config) {
                    $existing = ConfigModel::where('name', $name)->field('id,name')->find();
                    if ($existing) {
                        ConfigModel::where('name', $name)->update(['value' => $config['value']]);
                    } else {
                        \think\facade\Db::name('config')->insert([
                            'name' => $name,
                            'value' => $config['value'],
                            'title' => $config['title'],
                            'tip' => '用户注册成功后自动发放的奖励',
                            'type' => 'number',
                            'group' => 'register',
                            'content' => '',
                            'rule' => '',
                            'extend' => '',
                            'allow_del' => 1,
                            'weigh' => 0,
                        ]);
                    }
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('注册奖励配置更新成功');
            } else {
                $this->success('', [
                    'money' => (float)get_sys_config('register_reward_money', 0),
                    'score' => (int)get_sys_config('register_reward_score', 0),
                    'withdrawable_money' => (float)get_sys_config('register_reward_withdraw', 0),
                    'green_power' => (float)get_sys_config('register_reward_green', 0),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存手机号白名单配置
     * @throws Throwable
     */
    public function mobileWhitelistConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $enabled = (int)$this->request->post('enabled', 0);

                // 验证数据
                if (!in_array($enabled, [0, 1])) {
                    $this->error('配置值必须是0或1');
                }

                // 保存配置到系统配置表
                $configName = 'mobile_whitelist_enabled';
                $existing = ConfigModel::where('name', $configName)->field('id,name')->find();
                if ($existing) {
                    ConfigModel::where('name', $configName)->update(['value' => (string)$enabled]);
                } else {
                    \think\facade\Db::name('config')->insert([
                        'name' => $configName,
                        'value' => (string)$enabled,
                        'title' => '手机号白名单验证开关',
                        'tip' => '启用后，只有白名单中的手机号才能注册',
                        'type' => 'switch',
                        'group' => 'register',
                        'content' => '',
                        'rule' => '',
                        'extend' => '',
                        'allow_del' => 1,
                        'weigh' => 0,
                    ]);
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('手机号白名单配置更新成功');
            } else {
                $this->success('', [
                    'enabled' => (int)get_sys_config('mobile_whitelist_enabled', 0),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存邀请码注册配置
     * @throws Throwable
     */
    public function inviteCodeRegisterConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $enabled = (int)$this->request->post('enabled', 0);

                // 验证数据
                if (!in_array($enabled, [0, 1])) {
                    $this->error('配置值必须是0或1');
                }

                // 保存配置到系统配置表
                $configName = 'invite_code_register';
                $existing = ConfigModel::where('name', $configName)->field('id,name')->find();
                if ($existing) {
                    ConfigModel::where('name', $configName)->update(['value' => (string)$enabled]);
                } else {
                    \think\facade\Db::name('config')->insert([
                        'name' => $configName,
                        'value' => (string)$enabled,
                        'title' => '邀请码注册开关',
                        'tip' => '启用后，注册时必须填写有效的邀请码',
                        'type' => 'switch',
                        'group' => 'register',
                        'content' => '',
                        'rule' => '',
                        'extend' => '',
                        'allow_del' => 1,
                        'weigh' => 0,
                    ]);
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('邀请码注册配置更新成功');
            } else {
                $this->success('', [
                    'enabled' => (int)get_sys_config('invite_code_register', 0),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存签到积分配置
     * @throws Throwable
     */
    public function signInConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $dailyReward = (int)$this->request->post('daily_reward', 0);
                $referrerReward = (int)$this->request->post('referrer_reward', 0);

                ConfigModel::where('name', 'sign_in_daily_score')->update(['value' => (string)max(0, $dailyReward)]);
                ConfigModel::where('name', 'sign_in_referrer_score')->update(['value' => (string)max(0, $referrerReward)]);
                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('签到奖励配置更新成功');
            } else {
                $this->success('', [
                    'daily_reward' => (int)get_sys_config('sign_in_daily_score'),
                    'referrer_reward' => (int)get_sys_config('sign_in_referrer_score'),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存签到活动配置
     * @throws Throwable
     */
    public function signInActivityConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $registerReward = (float)$this->request->post('register_reward', 0);
                $registerGreenPower = (float)$this->request->post('register_green_power', 0);
                $withdrawMinAmount = (float)$this->request->post('withdraw_min_amount', 10);
                $withdrawDailyLimit = (int)$this->request->post('withdraw_daily_limit', 1);
                $withdrawAuditHours = (int)$this->request->post('withdraw_audit_hours', 24);

                $configs = [
                    'sign_act_reg_reward' => max(0, $registerReward),
                    'sign_act_reg_green' => max(0, $registerGreenPower),
                    'withdraw_min_amount' => max(0, $withdrawMinAmount),
                    'withdraw_daily_limit' => max(0, $withdrawDailyLimit),
                    'withdraw_audit_hours' => max(0, min(168, $withdrawAuditHours)),
                ];

                foreach ($configs as $name => $value) {
                    $config = ConfigModel::where('name', $name)->find();
                    if ($config) {
                        ConfigModel::where('name', $name)->update(['value' => (string)$value]);
                    } else {
                        \think\facade\Db::name('config')->insert([
                            'name' => $name,
                            'value' => (string)$value,
                            'title' => $name,
                            'tip' => '',
                            'type' => 'number',
                            'group' => 'sign_in_activity',
                            'content' => '',
                            'rule' => '',
                            'extend' => '',
                            'allow_del' => 1,
                            'weigh' => 0,
                        ]);
                    }
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('签到活动配置更新成功');
            } else {
                $this->success('', [
                    'register_reward' => (float)get_sys_config('sign_act_reg_reward', 0),
                    'register_green_power' => (float)get_sys_config('sign_act_reg_green', 0),
                    'withdraw_min_amount' => (float)get_sys_config('withdraw_min_amount', 10),
                    'withdraw_daily_limit' => (int)get_sys_config('withdraw_daily_limit', 1),
                    'withdraw_audit_hours' => (int)get_sys_config('withdraw_audit_hours', 24),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存抽奖配置
     * @throws Throwable
     */
    public function luckyDrawConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $dailyDrawLimit = (int)$this->request->post('daily_draw_limit', 0);
                $drawScoreCost = (int)$this->request->post('draw_score_cost', 0);
                $dailyLimitResetHour = (int)$this->request->post('daily_limit_reset_hour', 0);
                $prizeSendAuto = (int)$this->request->post('prize_send_auto', 0);
                $promotionalVideoTitle = (string)$this->request->post('promotional_video_title', '');
                $promotionalVideoSummary = (string)$this->request->post('promotional_video_summary', '');
                $promotionalVideo = (string)$this->request->post('promotional_video', '');
                $drawRules = (string)$this->request->post('draw_rules', '');
                $customerServiceUrl = (string)$this->request->post('customer_service_url', '');

                // 验证数据
                if ($dailyDrawLimit < 0) {
                    $this->error('每天允许抽奖次数必须为非负数');
                }
                if ($drawScoreCost < 0) {
                    $this->error('每次抽奖消耗积分必须为非负数');
                }
                if ($dailyLimitResetHour < 0 || $dailyLimitResetHour > 23) {
                    $this->error('每日限制重置时间必须在0-23之间');
                }
                if (!in_array($prizeSendAuto, [0, 1])) {
                    $this->error('是否自动发放奖品必须为0或1');
                }

                // 更新配置
                LuckyDraw::setConfig('daily_draw_limit', (string)$dailyDrawLimit, '用户每天可以抽奖的次数');
                LuckyDraw::setConfig('draw_score_cost', (string)$drawScoreCost, '每次抽奖需要消耗的积分数量');
                LuckyDraw::setConfig('daily_limit_reset_hour', (string)$dailyLimitResetHour, '每天何时重置每日次数（0-23）');
                LuckyDraw::setConfig('prize_send_auto', (string)$prizeSendAuto, '是否自动发放奖品（1=是，0=否）');
                LuckyDraw::setConfig('promotional_video_title', $promotionalVideoTitle, '抽奖页面宣传视频标题');
                LuckyDraw::setConfig('promotional_video_summary', $promotionalVideoSummary, '抽奖页面宣传视频摘要');
                LuckyDraw::setConfig('promotional_video', $promotionalVideo, '抽奖页面宣传视频URL');
                LuckyDraw::setConfig('draw_rules', $drawRules, '抽奖规则说明文字');
                LuckyDraw::setConfig('customer_service_url', $customerServiceUrl, '客服跳转链接URL');

                $this->success('抽奖配置更新成功');
            } else {
                $this->success('', [
                    'daily_draw_limit' => (int)LuckyDraw::getConfig('daily_draw_limit', 5),
                    'draw_score_cost' => (int)LuckyDraw::getConfig('draw_score_cost', 0),
                    'daily_limit_reset_hour' => (int)LuckyDraw::getConfig('daily_limit_reset_hour', 0),
                    'prize_send_auto' => (int)LuckyDraw::getConfig('prize_send_auto', 0),
                    'promotional_video_title' => LuckyDraw::getConfig('promotional_video_title', ''),
                    'promotional_video_summary' => LuckyDraw::getConfig('promotional_video_summary', ''),
                    'promotional_video' => LuckyDraw::getConfig('promotional_video', ''),
                    'draw_rules' => LuckyDraw::getConfig('draw_rules', ''),
                    'customer_service_url' => LuckyDraw::getConfig('customer_service_url', ''),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存客服链接配置
     * @throws Throwable
     */
    public function customerServiceConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $customerServiceUrl = (string)$this->request->post('customer_service_url', '');

                // 更新配置
                LuckyDraw::setConfig('customer_service_url', $customerServiceUrl, '客服跳转链接URL');

                $this->success('客服链接配置更新成功');
            } else {
                $this->success('', [
                    'customer_service_url' => LuckyDraw::getConfig('customer_service_url', ''),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存理财产品配置
     * @throws Throwable
     */
    public function financeProductConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $agreementContent = (string)$this->request->post('agreement_content', '');

                // 检查配置是否存在
                $config = ConfigModel::where('name', 'finance_agreement_content')->field('id,name')->find();
                
                if ($config) {
                    // 更新现有配置
                    ConfigModel::where('name', 'finance_agreement_content')->update(['value' => $agreementContent]);
                } else {
                    // 使用 DB 直接插入，避免模型的 append 属性干扰
                    \think\facade\Db::name('config')->insert([
                        'name' => 'finance_agreement_content',
                        'value' => $agreementContent,
                        'title' => '理财产品委托协议内容',
                        'tip' => '所有理财产品共用的委托协议内容',
                        'type' => 'editor',
                        'group' => 'finance',
                        'content' => '',
                        'rule' => '',
                        'extend' => '',
                        'allow_del' => 1,
                        'weigh' => 0,
                    ]);
                }
                
                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('理财产品配置更新成功');
            } else {
                $this->success('', [
                    'agreement_content' => (string)get_sys_config('finance_agreement_content'),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存排行榜奖励配置
     * @throws Throwable
     */
    public function leaderboardRewardConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $rewards = $this->request->post('rewards', []);

                // 验证数据
                if (!is_array($rewards)) {
                    $this->error('排行榜奖励配置必须是数组');
                }

                // 验证每个奖励项
                $validatedRewards = [];
                for ($rank = 1; $rank <= 10; $rank++) {
                    $percentage = isset($rewards[$rank]) ? (float)$rewards[$rank] : 0;
                    if ($percentage < 0 || $percentage > 100) {
                        $this->error("第{$rank}名的奖励百分比必须在0-100之间");
                    }
                    $validatedRewards[$rank] = $percentage;
                }

                // 保存配置（使用JSON格式）
                LuckyDraw::setConfig('leaderboard_rewards', json_encode($validatedRewards), '排行榜奖励百分比配置（1-10名）');

                $this->success('排行榜奖励配置更新成功');
            } else {
                // 获取配置
                $configValue = LuckyDraw::getConfig('leaderboard_rewards', '{}');
                $rewards = json_decode($configValue, true) ?: [];

                // 确保返回完整的1-10名配置
                $fullRewards = [];
                for ($rank = 1; $rank <= 10; $rank++) {
                    $fullRewards[$rank] = isset($rewards[$rank]) ? (float)$rewards[$rank] : 0;
                }

                $this->success('', [
                    'rewards' => $fullRewards,
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存藏品价格上涨配置
     * @throws Throwable
     */
    public function collectionPriceIncreaseConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $minIncrease = (float)$this->request->post('min_increase', 0.04);
                $maxIncrease = (float)$this->request->post('max_increase', 0.06);

                // 验证数据
                if ($minIncrease < 0 || $minIncrease > 1) {
                    $this->error('最小涨幅必须在0-1之间（0表示0%，1表示100%）');
                }
                if ($maxIncrease < 0 || $maxIncrease > 1) {
                    $this->error('最大涨幅必须在0-1之间（0表示0%，1表示100%）');
                }
                if ($minIncrease > $maxIncrease) {
                    $this->error('最小涨幅不能大于最大涨幅');
                }

                // 更新配置
                ConfigModel::where('name', 'collection_price_increase_min')->update(['value' => (string)$minIncrease]);
                ConfigModel::where('name', 'collection_price_increase_max')->update(['value' => (string)$maxIncrease]);
                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('藏品价格上涨配置更新成功');
            } else {
                $this->success('', [
                    'min_increase' => (float)get_sys_config('collection_price_increase_min', 0.04),
                    'max_increase' => (float)get_sys_config('collection_price_increase_max', 0.06),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存寄售服务费费率配置
     * @throws Throwable
     */
    public function consignmentServiceFeeConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $serviceFeeRate = (float)$this->request->post('service_fee_rate', 0.03);

                // 验证数据
                if ($serviceFeeRate < 0 || $serviceFeeRate > 1) {
                    $this->error('寄售服务费费率必须在0-1之间（0表示0%，1表示100%）');
                }

                // 更新配置
                ConfigModel::where('name', 'consignment_service_fee_rate')->update(['value' => (string)$serviceFeeRate]);
                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('寄售服务费费率配置更新成功');
            } else {
                $this->success('', [
                    'service_fee_rate' => (float)get_sys_config('consignment_service_fee_rate', 0.03),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存寄售券有效期配置
     * @throws Throwable
     */
    public function consignmentCouponExpireConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $expireDays = (int)$this->request->post('expire_days', 7);

                // 验证数据
                if ($expireDays < 1 || $expireDays > 365) {
                    $this->error('寄售券有效期必须在1-365天之间');
                }

                // 检查配置是否存在，不存在则创建
                $config = ConfigModel::where('name', 'consignment_coupon_expire_days')->find();
                if ($config) {
                    // 更新现有配置
                    ConfigModel::where('name', 'consignment_coupon_expire_days')->update(['value' => (string)$expireDays]);
                } else {
                    // 创建新配置
                    ConfigModel::create([
                        'name' => 'consignment_coupon_expire_days',
                        'group' => 'collection',
                        'title' => '寄售券有效期（天）',
                        'tip' => '用户购买商品后获得的寄售券有效期天数',
                        'type' => 'number',
                        'value' => (string)$expireDays,
                        'content' => '',
                        'rule' => 'required|integer|between:1,365',
                        'extend' => '',
                        'allow_del' => 1,
                        'weigh' => 0,
                    ]);
                }
                
                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('寄售券有效期配置更新成功');
            } else {
                $this->success('', [
                    'expire_days' => (int)get_sys_config('consignment_coupon_expire_days', 7),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存寄售解锁小时数配置（购买后多少小时允许寄售）
     * 注意：不使用默认硬编码，必须由后台显式配置
     * @throws Throwable
     */
    public function consignmentUnlockHoursConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $hours = (int)$this->request->post('unlock_hours', 0);

                // 验证数据：必须显式设置为0-8760小时之间（0表示购买后即可寄售）
                if ($hours < 0 || $hours > 8760) {
                    $this->error('寄售解锁小时数必须在0-8760小时之间（0表示购买后即可寄售）');
                }

                // 检查配置是否存在，不存在则创建
                $config = ConfigModel::where('name', 'consignment_unlock_hours')->find();
                if ($config) {
                    ConfigModel::where('name', 'consignment_unlock_hours')->update(['value' => (string)$hours]);
                } else {
                    \think\facade\Db::name('config')->insert([
                        'name' => 'consignment_unlock_hours',
                        'value' => (string)$hours,
                        'title' => '寄售解锁小时数（小时）',
                        'tip' => '购买后多少小时允许用户上架寄售，单位：小时（0表示购买后即可寄售）；请在此处显式配置该值，系统不再使用默认硬编码',
                        'type' => 'number',
                        'group' => 'collection',
                        'content' => '',
                        'rule' => 'required|integer|between:0,8760',
                        'extend' => '',
                        'allow_del' => 1,
                        'weigh' => 0,
                    ]);
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('寄售解锁小时数配置更新成功');
            } else {
                // 不提供默认值，前端根据是否存在显示空或当前值
                $this->success('', [
                    'unlock_hours' => get_sys_config('consignment_unlock_hours'),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存寄售失败（流拍）天数配置
     * @throws Throwable
     */
    public function consignmentExpireDaysConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $expireDays = (int)$this->request->post('expire_days', 7);

                // 验证数据
                if ($expireDays < 1 || $expireDays > 365) {
                    $this->error('寄售失败天数必须在1-365天之间');
                }

                // 检查配置是否存在，不存在则创建
                $config = ConfigModel::where('name', 'consignment_expire_days')->find();
                if ($config) {
                    // 更新现有配置
                    ConfigModel::where('name', 'consignment_expire_days')->update(['value' => (string)$expireDays]);
                } else {
                    // 使用 DB 直接插入，避免模型的 append 属性干扰
                    \think\facade\Db::name('config')->insert([
                        'name' => 'consignment_expire_days',
                        'value' => (string)$expireDays,
                        'title' => '寄售失败（流拍）天数',
                        'tip' => '寄售超过指定天数未售出时，自动标记为流拍失败',
                        'type' => 'number',
                        'group' => 'collection',
                        'content' => '',
                        'rule' => 'required|integer|between:1,365',
                        'extend' => '',
                        'allow_del' => 1,
                        'weigh' => 0,
                    ]);
                }
                
                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('寄售失败天数配置更新成功');
            } else {
                $this->success('', [
                    'expire_days' => (int)get_sys_config('consignment_expire_days', 7),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存交易结算利润分配配置
     * @throws Throwable
     */
    public function consignmentSettlementConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $profitBalanceRate = (float)$this->request->post('profit_balance_rate', 0.5);
                $profitScoreRate = (float)$this->request->post('profit_score_rate', 0.5);

                // 验证数据
                if ($profitBalanceRate < 0 || $profitBalanceRate > 1) {
                    $this->error('利润余额分配比例必须在0-1之间（0表示0%，1表示100%）');
                }
                if ($profitScoreRate < 0 || $profitScoreRate > 1) {
                    $this->error('利润积分分配比例必须在0-1之间（0表示0%，1表示100%）');
                }
                
                // 验证两个比例之和是否为1
                $totalRate = $profitBalanceRate + $profitScoreRate;
                if (abs($totalRate - 1.0) > 0.0001) {
                    $this->error('利润余额分配比例和利润积分分配比例之和必须等于1（100%）');
                }

                // 更新配置（使用较短的配置名称，因为name字段限制为30字符）
                $configNames = [
                    'consignment_profit_balance' => $profitBalanceRate,
                    'consignment_profit_score' => $profitScoreRate,
                ];
                
                foreach ($configNames as $name => $value) {
                    $config = ConfigModel::where('name', $name)->find();
                    if ($config) {
                        ConfigModel::where('name', $name)->update(['value' => (string)$value]);
                    } else {
                        $titleMap = [
                            'consignment_profit_balance' => '交易结算利润余额分配比例',
                            'consignment_profit_score' => '交易结算利润积分分配比例',
                        ];
                        $tipMap = [
                            'consignment_profit_balance' => '交易结算时，利润中分配给余额的比例（0-1之间，例如0.5表示50%）',
                            'consignment_profit_score' => '交易结算时，利润中分配给积分的比例（0-1之间，例如0.5表示50%）',
                        ];
                        \think\facade\Db::name('config')->insert([
                            'name' => $name,
                            'value' => (string)$value,
                            'title' => $titleMap[$name],
                            'tip' => $tipMap[$name],
                            'type' => 'number',
                            'group' => 'collection',
                            'content' => '',
                            'rule' => 'required|numeric|between:0,1',
                            'extend' => '',
                            'allow_del' => 1,
                            'weigh' => 0,
                        ]);
                    }
                }
                
                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('交易结算利润分配配置更新成功');
            } else {
                $this->success('', [
                    'profit_balance_rate' => (float)get_sys_config('consignment_profit_balance', 0.5),
                    'profit_score_rate' => (float)get_sys_config('consignment_profit_score', 0.5),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存撮合池（中签）利润分配及平局处理配置
     * @throws Throwable
     */
    public function matchingSettlementConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $matchingProfitRate = (float)$this->request->post('matching_profit_rate', 0.5);
                $profitBalanceRate = (float)$this->request->post('matching_profit_balance', 0.5);
                $profitScoreRate = (float)$this->request->post('matching_profit_score', 0.5);
                $tieBreaker = (string)$this->request->post('matching_tie_breaker', 'time'); // 'time' or 'random'

                // 验证数据
                if ($matchingProfitRate < 0 || $matchingProfitRate > 1) {
                    $this->error('撮合利润比例必须在0-1之间（0表示0%，1表示100%）');
                }
                if ($profitBalanceRate < 0 || $profitBalanceRate > 1) {
                    $this->error('撮合利润到可调度收益的比例必须在0-1之间（0表示0%，1表示100%）');
                }
                if ($profitScoreRate < 0 || $profitScoreRate > 1) {
                    $this->error('撮合利润到消费金的比例必须在0-1之间（0表示0%，1表示100%）');
                }
                $totalRate = $profitBalanceRate + $profitScoreRate;
                if (abs($totalRate - 1.0) > 0.0001) {
                    $this->error('撮合利润分配比例之和必须等于1（100%）');
                }
                if (!in_array($tieBreaker, ['time', 'random'])) {
                    $this->error('平局处理配置无效，只能是 time 或 random');
                }

                // 更新/创建配置
                $configs = [
                    'matching_profit_rate' => (string)$matchingProfitRate,
                    'matching_profit_balance' => (string)$profitBalanceRate,
                    'matching_profit_score' => (string)$profitScoreRate,
                    'matching_tie_breaker' => $tieBreaker,
                ];

                foreach ($configs as $name => $value) {
                    $config = ConfigModel::where('name', $name)->find();
                    if ($config) {
                        ConfigModel::where('name', $name)->update(['value' => (string)$value]);
                    } else {
                        $titleMap = [
                            'matching_profit_rate' => '撮合中利润比例（相对于价格）',
                            'matching_profit_balance' => '撮合利润分配到可调度收益的比例',
                            'matching_profit_score' => '撮合利润分配到消费金的比例',
                            'matching_tie_breaker' => '撮合权重相同时的平局处理方式',
                        ];
                        $tipMap = [
                            'matching_profit_rate' => '中签时利润占比（0-1，例如0.5表示利润为价格的50%）',
                            'matching_profit_balance' => '利润中分配给可调度收益的比例（0-1，例如0.5表示50%）',
                            'matching_profit_score' => '利润中分配给消费金的比例（0-1，例如0.5表示50%）',
                            'matching_tie_breaker' => '当候选权重相同时的处理：time=按时间早到晚, random=随机抽取',
                        ];
                        $type = ($name === 'matching_tie_breaker') ? 'string' : 'number';
                        \think\facade\Db::name('config')->insert([
                            'name' => $name,
                            'value' => (string)$value,
                            'title' => $titleMap[$name],
                            'tip' => $tipMap[$name],
                            'type' => $type,
                            'group' => 'collection',
                            'content' => '',
                            'rule' => $type === 'number' ? 'required|numeric|between:0,1' : '',
                            'extend' => '',
                            'allow_del' => 1,
                            'weigh' => 0,
                        ]);
                    }
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('撮合利润与平局配置更新成功');
            } else {
                $this->success('', [
                    'matching_profit_rate' => (float)get_sys_config('matching_profit_rate', 0.5),
                    'matching_profit_balance' => (float)get_sys_config('matching_profit_balance', 0.5),
                    'matching_profit_score' => (float)get_sys_config('matching_profit_score', 0.5),
                    'matching_tie_breaker' => (string)get_sys_config('matching_tie_breaker', 'time'),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存兜底熔断配置
     * @throws Throwable
     */
    public function collectionMiningConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $continuousFailCount = (int)$this->request->post('continuous_fail_count', 5);
                $longTermDays = (int)$this->request->post('long_term_days', 7);
                $priceTopMultiple = (float)$this->request->post('price_top_multiple', 7.0);
                $dividendBalanceRate = (float)$this->request->post('dividend_balance_rate', 0.5);
                $dividendScoreRate = (float)$this->request->post('dividend_score_rate', 0.5);
                $dailyDividendAmount = (float)$this->request->post('daily_dividend_amount', 0);
                $dividendPriceRate = (float)$this->request->post('dividend_price_rate', 0);

                // 验证数据
                if ($continuousFailCount < 1 || $continuousFailCount > 100) {
                    $this->error('连续失败次数必须在1-100之间');
                }
                if ($longTermDays < 1 || $longTermDays > 365) {
                    $this->error('长期滞销天数必须在1-365之间');
                }
                if ($priceTopMultiple < 1 || $priceTopMultiple > 100) {
                    $this->error('价格触顶倍数必须在1-100之间');
                }
                if ($dividendBalanceRate < 0 || $dividendBalanceRate > 1) {
                    $this->error('分红余额分配比例必须在0-1之间');
                }
                if ($dividendScoreRate < 0 || $dividendScoreRate > 1) {
                    $this->error('分红积分分配比例必须在0-1之间');
                }
                $totalRate = $dividendBalanceRate + $dividendScoreRate;
                if (abs($totalRate - 1.0) > 0.0001) {
                    $this->error('分红余额分配比例和分红积分分配比例之和必须等于1（100%）');
                }
                if ($dailyDividendAmount < 0) {
                    $this->error('每日分红金额必须为非负数');
                }
                if ($dividendPriceRate < 0 || $dividendPriceRate > 1) {
                    $this->error('分红价格比例必须在0-1之间（0表示不按价格比例分红，0.01表示1%）');
                }

                // 更新配置
                $configs = [
                    'mining_continuous_fail' => (string)$continuousFailCount,
                    'mining_long_term_days' => (string)$longTermDays,
                    'mining_price_top_multiple' => (string)$priceTopMultiple,
                    'mining_dividend_balance' => (string)$dividendBalanceRate,
                    'mining_dividend_score' => (string)$dividendScoreRate,
                    'mining_daily_dividend' => (string)$dailyDividendAmount,
                    'mining_dividend_price_rate' => (string)$dividendPriceRate,
                ];
                
                foreach ($configs as $name => $value) {
                    $config = ConfigModel::where('name', $name)->find();
                    if ($config) {
                        ConfigModel::where('name', $name)->update(['value' => $value]);
                    } else {
                        $titleMap = [
                            'mining_continuous_fail' => '连续失败次数',
                            'mining_long_term_days' => '长期滞销天数',
                            'mining_price_top_multiple' => '价格触顶倍数',
                            'mining_dividend_balance' => '分红余额分配比例',
                            'mining_dividend_score' => '分红积分分配比例',
                            'mining_daily_dividend' => '每日分红金额',
                            'mining_dividend_price_rate' => '分红价格比例',
                        ];
                        $tipMap = [
                            'mining_continuous_fail' => '连续寄售失败次数达到此值时，触发强制锁仓转为矿机',
                            'mining_long_term_days' => '持有超过此天数还没卖掉（或没操作上架）时，触发强制锁仓转为矿机',
                            'mining_price_top_multiple' => '现价超过发行价的此倍数时，触发强制锁仓转为矿机',
                            'mining_dividend_balance' => '矿机每日分红中分配给余额的比例（0-1之间，例如0.5表示50%）',
                            'mining_dividend_score' => '矿机每日分红中分配给积分的比例（0-1之间，例如0.5表示50%）',
                            'mining_daily_dividend' => '矿机每日分红总金额（元），当价格比例为0时使用此固定金额',
                            'mining_dividend_price_rate' => '按藏品当前价格的比例计算分红（0-1之间，例如0.01表示1%；设置为0则使用固定金额分红）',
                        ];
                        \think\facade\Db::name('config')->insert([
                            'name' => $name,
                            'value' => $value,
                            'title' => $titleMap[$name],
                            'tip' => $tipMap[$name],
                            'type' => $name === 'mining_daily_dividend' ? 'number' : ($name === 'mining_price_top_multiple' || strpos($name, 'dividend') !== false ? 'number' : 'number'),
                            'group' => 'collection',
                            'content' => '',
                            'rule' => '',
                            'extend' => '',
                            'allow_del' => 1,
                            'weigh' => 0,
                        ]);
                    }
                }
                
                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('兜底熔断配置更新成功');
            } else {
                $this->success('', [
                    'continuous_fail_count' => (int)get_sys_config('mining_continuous_fail', 5),
                    'long_term_days' => (int)get_sys_config('mining_long_term_days', 7),
                    'price_top_multiple' => (float)get_sys_config('mining_price_top_multiple', 7.0),
                    'dividend_balance_rate' => (float)get_sys_config('mining_dividend_balance', 0.5),
                    'dividend_score_rate' => (float)get_sys_config('mining_dividend_score', 0.5),
                    'daily_dividend_amount' => (float)get_sys_config('mining_daily_dividend', 0),
                    'dividend_price_rate' => (float)get_sys_config('mining_dividend_price_rate', 0),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存代理佣金配置
     * @throws Throwable
     */
    public function agentCommissionConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $directRate = (float)$this->request->post('direct_rate', 0.10);
                $indirectRate = (float)$this->request->post('indirect_rate', 0.05);
                $teamLevel1Rate = (float)$this->request->post('team_level1_rate', 0.09);
                $teamLevel2Rate = (float)$this->request->post('team_level2_rate', 0.12);
                $teamLevel3Rate = (float)$this->request->post('team_level3_rate', 0.15);
                $teamLevel4Rate = (float)$this->request->post('team_level4_rate', 0.18);
                $teamLevel5Rate = (float)$this->request->post('team_level5_rate', 0.21);
                $sameLevelRate = (float)$this->request->post('same_level_rate', 0.10);
                $serviceFeeDiscount = (float)$this->request->post('service_fee_discount', 1.0);

                // 验证数据
                if ($directRate < 0 || $directRate > 1) {
                    $this->error('直推佣金比例必须在0-1之间');
                }
                if ($indirectRate < 0 || $indirectRate > 1) {
                    $this->error('间推佣金比例必须在0-1之间');
                }
                if ($teamLevel1Rate < 0 || $teamLevel1Rate > 1) {
                    $this->error('1级代理团队奖比例必须在0-1之间');
                }
                if ($teamLevel2Rate < 0 || $teamLevel2Rate > 1) {
                    $this->error('2级代理团队奖比例必须在0-1之间');
                }
                if ($teamLevel3Rate < 0 || $teamLevel3Rate > 1) {
                    $this->error('3级代理团队奖比例必须在0-1之间');
                }
                if ($teamLevel4Rate < 0 || $teamLevel4Rate > 1) {
                    $this->error('4级代理团队奖比例必须在0-1之间');
                }
                if ($teamLevel5Rate < 0 || $teamLevel5Rate > 1) {
                    $this->error('5级代理团队奖比例必须在0-1之间');
                }
                if ($sameLevelRate < 0 || $sameLevelRate > 1) {
                    $this->error('同级奖比例必须在0-1之间');
                }
                if ($serviceFeeDiscount < 0 || $serviceFeeDiscount > 1) {
                    $this->error('代理服务费折扣必须在0-1之间（1表示不打折，0.8表示8折）');
                }

                // 更新配置
                $configs = [
                    'agent_direct_rate' => (string)$directRate,
                    'agent_indirect_rate' => (string)$indirectRate,
                    'agent_team_level1' => (string)$teamLevel1Rate,
                    'agent_team_level2' => (string)$teamLevel2Rate,
                    'agent_team_level3' => (string)$teamLevel3Rate,
                    'agent_team_level4' => (string)$teamLevel4Rate,
                    'agent_team_level5' => (string)$teamLevel5Rate,
                    'agent_same_level_rate' => (string)$sameLevelRate,
                    'agent_service_discount' => (string)$serviceFeeDiscount,
                ];
                
                foreach ($configs as $name => $value) {
                    $config = ConfigModel::where('name', $name)->find();
                    if ($config) {
                        ConfigModel::where('name', $name)->update(['value' => $value]);
                    } else {
                        $titleMap = [
                            'agent_direct_rate' => '直推佣金比例',
                            'agent_indirect_rate' => '间推佣金比例',
                            'agent_team_level1' => '1级代理团队奖比例',
                            'agent_team_level2' => '2级代理团队奖比例',
                            'agent_team_level3' => '3级代理团队奖比例',
                            'agent_team_level4' => '4级代理团队奖比例',
                            'agent_team_level5' => '5级代理团队奖比例',
                            'agent_same_level_rate' => '同级奖比例',
                            'agent_service_discount' => '代理服务费折扣',
                        ];
                        $tipMap = [
                            'agent_direct_rate' => '直推拿利润的比例（0-1之间，例如0.10表示10%）',
                            'agent_indirect_rate' => '间推拿利润的比例（0-1之间，例如0.05表示5%）',
                            'agent_team_level1' => '1级代理团队奖比例（0-1之间，例如0.09表示9%）',
                            'agent_team_level2' => '2级代理团队奖比例（0-1之间，例如0.12表示12%）',
                            'agent_team_level3' => '3级代理团队奖比例（0-1之间，例如0.15表示15%）',
                            'agent_team_level4' => '4级代理团队奖比例（0-1之间，例如0.18表示18%）',
                            'agent_team_level5' => '5级代理团队奖比例（0-1之间，例如0.21表示21%）',
                            'agent_same_level_rate' => '同级代理拿的固定比例（0-1之间，例如0.10表示10%，当上级和下级是同一等级时使用）',
                            'agent_service_discount' => '代理在上架寄售时，服务费的折扣（0-1之间，例如0.8表示8折，1表示不打折）',
                        ];
                        \think\facade\Db::name('config')->insert([
                            'name' => $name,
                            'value' => $value,
                            'title' => $titleMap[$name],
                            'tip' => $tipMap[$name],
                            'type' => 'number',
                            'group' => 'agent',
                            'content' => '',
                            'rule' => '',
                            'extend' => '',
                            'allow_del' => 1,
                            'weigh' => 0,
                        ]);
                    }
                }
                
                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('代理佣金配置更新成功');
            } else {
                $this->success('', [
                    'direct_rate' => (float)get_sys_config('agent_direct_rate', 0.10),
                    'indirect_rate' => (float)get_sys_config('agent_indirect_rate', 0.05),
                    'team_level1_rate' => (float)get_sys_config('agent_team_level1', 0.09),
                    'team_level2_rate' => (float)get_sys_config('agent_team_level2', 0.12),
                    'team_level3_rate' => (float)get_sys_config('agent_team_level3', 0.15),
                    'team_level4_rate' => (float)get_sys_config('agent_team_level4', 0.18),
                    'team_level5_rate' => (float)get_sys_config('agent_team_level5', 0.21),
                    'same_level_rate' => (float)get_sys_config('agent_same_level_rate', 0.10),
                    'service_fee_discount' => (float)get_sys_config('agent_service_discount', 1.0),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存网易易盾OCR配置
     * @throws Throwable
     */
    public function yidunOcrConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $businessId = (string)$this->request->post('businessId', '');
                $realPersonBusinessId = (string)$this->request->post('realPersonBusinessId', '');
                $livenessBusinessId = (string)$this->request->post('livenessBusinessId', '');
                $h5FaceBusinessId = (string)$this->request->post('h5FaceBusinessId', '');
                $appFaceBusinessId = (string)$this->request->post('appFaceBusinessId', '');
                $secretId = (string)$this->request->post('secretId', '');
                $secretKey = (string)$this->request->post('secretKey', '');
                $apiUrl = (string)$this->request->post('api_url', '');
                $realPersonApiUrl = (string)$this->request->post('real_person_api_url', '');
                $livePersonBusinessId = (string)$this->request->post('livePersonBusinessId', '');
                $livePersonApiUrl = (string)$this->request->post('live_person_api_url', '');
                $livePersonRecheckBusinessId = (string)$this->request->post('livePersonRecheckBusinessId', '');
                $livePersonRecheckApiUrl = (string)$this->request->post('live_person_recheck_api_url', '');

                $configs = [
                    'yidun_ocr_business_id' => $businessId,
                    'yidun_real_person_business_id' => $realPersonBusinessId,
                    'yidun_liveness_business_id' => $livenessBusinessId,
                    'yidun_h5_face_business_id' => $h5FaceBusinessId,
                    'yidun_app_face_business_id' => $appFaceBusinessId,
                    'yidun_ocr_secret_id' => $secretId,
                    'yidun_ocr_secret_key' => $secretKey,
                    'yidun_ocr_api_url' => $apiUrl,
                    'yidun_real_person_api_url' => $realPersonApiUrl,
                    'yidun_live_person_business_id' => $livePersonBusinessId,
                    'yidun_live_person_api_url' => $livePersonApiUrl,
                    'yidun_live_recheck_biz' => $livePersonRecheckBusinessId,
                    'yidun_live_recheck_api' => $livePersonRecheckApiUrl,
                ];

                foreach ($configs as $name => $value) {
                    $config = ConfigModel::where('name', $name)->find();
                    if ($config) {
                        ConfigModel::where('name', $name)->update(['value' => $value]);
                    } else {
                        \think\facade\Db::name('config')->insert([
                            'name' => $name,
                            'value' => $value,
                            'title' => $name,
                            'tip' => $name,
                            'type' => 'string',
                            'group' => 'third',
                            'content' => '',
                            'rule' => '',
                            'extend' => '',
                            'allow_del' => 1,
                            'weigh' => 0,
                        ]);
                    }
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('配置已更新');
            } else {
                $this->success('', [
                    'businessId' => (string)get_sys_config('yidun_ocr_business_id', ''),
                    'realPersonBusinessId' => (string)get_sys_config('yidun_real_person_business_id', ''),
                    'livenessBusinessId' => (string)get_sys_config('yidun_liveness_business_id', ''),
                    'h5FaceBusinessId' => (string)get_sys_config('yidun_h5_face_business_id', ''),
                    'appFaceBusinessId' => (string)get_sys_config('yidun_app_face_business_id', ''),
                    'secretId' => (string)get_sys_config('yidun_ocr_secret_id', ''),
                    'secretKey' => (string)get_sys_config('yidun_ocr_secret_key', ''),
                    'api_url' => (string)get_sys_config('yidun_ocr_api_url', ''),
                    'real_person_api_url' => (string)get_sys_config('yidun_real_person_api_url', ''),
                    'livePersonBusinessId' => (string)get_sys_config('yidun_live_person_business_id', ''),
                    'live_person_api_url' => (string)get_sys_config('yidun_live_person_api_url', ''),
                    'livePersonRecheckBusinessId' => (string)get_sys_config('yidun_live_recheck_biz', ''),
                    'live_person_recheck_api_url' => (string)get_sys_config('yidun_live_recheck_api', ''),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 网易易盾身份证OCR识别
     * @throws Throwable
     */
    public function yidunOcrCheck(): void
    {
        try {
            if (!$this->request->isPost()) {
                $this->error('仅支持POST请求');
            }

            $picType       = (int)$this->request->post('picType', 0);
            $frontPicture  = (string)$this->request->post('frontPicture', '');
            $backPicture   = (string)$this->request->post('backPicture', '');
            $dataId        = (string)$this->request->post('dataId', '');

            $ocr     = new YidunOcr();
            $result  = $ocr->check($picType, $frontPicture, $backPicture, $dataId);

            $this->success('', $result);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 网易易盾交互式人脸核身
     * @throws Throwable
     */
    public function yidunLivePersonCheck(): void
    {
        try {
            if (!$this->request->isPost()) {
                $this->error('仅支持POST请求');
            }

            $name        = (string)$this->request->post('name', '');
            $cardNo      = (string)$this->request->post('cardNo', '');
            $token       = (string)$this->request->post('token', '');
            $needAvatar  = (string)$this->request->post('needAvatar', '');
            $picType     = $this->request->post('picType', null);
            $dataId      = (string)$this->request->post('dataId', '');

            $picTypeInt = null;
            if ($picType !== null && $picType !== '') {
                $picTypeInt = (int)$picType;
            }

            $ocr    = new YidunOcr();
            $result = $ocr->livePersonCheck($name, $cardNo, $token, $needAvatar, $picTypeInt, $dataId);

            $this->success('', $result);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 网易易盾单活体检测
     * @throws Throwable
     */
    public function yidunLivePersonRecheck(): void
    {
        try {
            if (!$this->request->isPost()) {
                $this->error('仅支持POST请求');
            }

            $token      = (string)$this->request->post('token', '');
            $needAvatar = (string)$this->request->post('needAvatar', '');
            $picType    = $this->request->post('picType', null);
            $dataId     = (string)$this->request->post('dataId', '');

            $picTypeInt = null;
            if ($picType !== null && $picType !== '') {
                $picTypeInt = (int)$picType;
            }

            $ocr    = new YidunOcr();
            $result = $ocr->livePersonRecheck($token, $needAvatar, $picTypeInt, $dataId);

            $this->success('', $result);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 网易易盾实人认证
     * @throws Throwable
     */
    public function yidunRealPersonCheck(): void
    {
        try {
            if (!$this->request->isPost()) {
                $this->error('仅支持POST请求');
            }

            $name        = (string)$this->request->post('name', '');
            $cardNo      = (string)$this->request->post('cardNo', '');
            $picType     = (int)$this->request->post('picType', 0);
            $avatar      = (string)$this->request->post('avatar', '');
            $dataId      = (string)$this->request->post('dataId', '');
            $encryptType = (string)$this->request->post('encryptType', '');

            $ocr     = new YidunOcr();
            $result  = $ocr->realPersonCheck($name, $cardNo, $picType, $avatar, $dataId, $encryptType);

            $this->success('', $result);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存短信宝配置
     * @throws Throwable
     */
    public function smsBaoConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $name = (string)$this->request->post('name', '');
                $username = (string)$this->request->post('username', '');
                $apiKey = (string)$this->request->post('api_key', '');
                $apiUrl = (string)$this->request->post('api_url', '');
                $queryUrl = (string)$this->request->post('query_url', '');

                $configs = [
                    'smsbao_name' => $name,
                    'smsbao_username' => $username,
                    'smsbao_api_key' => $apiKey,
                    'smsbao_api_url' => $apiUrl,
                    'smsbao_query_url' => $queryUrl,
                ];

                foreach ($configs as $k => $v) {
                    $config = ConfigModel::where('name', $k)->find();
                    if ($config) {
                        ConfigModel::where('name', $k)->update(['value' => $v]);
                    } else {
                        \think\facade\Db::name('config')->insert([
                            'name' => $k,
                            'value' => $v,
                            'title' => $k,
                            'tip' => $k,
                            'type' => 'string',
                            'group' => 'third',
                            'content' => '',
                            'rule' => '',
                            'extend' => '',
                            'allow_del' => 1,
                            'weigh' => 0,
                        ]);
                    }
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();
                $this->success('配置已更新');
            } else {
                $this->success('', [
                    'name' => (string)get_sys_config('smsbao_name', ''),
                    'username' => (string)get_sys_config('smsbao_username', ''),
                    'api_key' => (string)get_sys_config('smsbao_api_key', ''),
                    'api_url' => (string)get_sys_config('smsbao_api_url', ''),
                    'query_url' => (string)get_sys_config('smsbao_query_url', ''),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 短信宝发送短信
     * @throws Throwable
     */
    public function smsBaoSend(): void
    {
        try {
            if (!$this->request->isPost()) {
                $this->error('仅支持POST请求');
            }

            $phone = (string)$this->request->post('phone', '');
            $content = (string)$this->request->post('content', '');
            $goodsId = (string)$this->request->post('goodsId', '');

            if (empty($phone)) {
                $this->error('手机号不能为空');
            }
            if (empty($content)) {
                $this->error('短信内容不能为空');
            }

            $smsBao = new SmsBao();
            $result = $smsBao->send($phone, $content, $goodsId ?: null);

            if ($result['code'] === 0) {
                $this->success($result['msg'], $result['data']);
            } else {
                $this->error($result['msg'], $result['data'], $result['code']);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 短信宝查询余额
     * @throws Throwable
     */
    public function smsBaoQueryBalance(): void
    {
        try {
            $smsBao = new SmsBao();
            $result = $smsBao->queryBalance();

            if ($result['code'] === 0) {
                $this->success($result['msg'], $result['data']);
            } else {
                $this->error($result['msg'], $result['data'], $result['code']);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存微网短信配置
     * @throws Throwable
     */
    public function weiWebsSmsConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $name = (string)$this->request->post('name', '');
                $account = (string)$this->request->post('account', '');
                $password = (string)$this->request->post('password', '');
                $apiUrl = (string)$this->request->post('api_url', '');

                $configs = [
                    'weiwebs_name' => $name,
                    'weiwebs_account' => $account,
                    'weiwebs_password' => $password,
                    'weiwebs_api_url' => $apiUrl,
                ];

                foreach ($configs as $k => $v) {
                    $config = ConfigModel::where('name', $k)->find();
                    if ($config) {
                        ConfigModel::where('name', $k)->update(['value' => $v]);
                    } else {
                        \think\facade\Db::name('config')->insert([
                            'name' => $k,
                            'value' => $v,
                            'title' => $k,
                            'tip' => $k,
                            'type' => 'string',
                            'group' => 'third',
                            'content' => '',
                            'rule' => '',
                            'extend' => '',
                            'allow_del' => 1,
                            'weigh' => 0,
                        ]);
                    }
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();
                $this->success('配置已更新');
            } else {
                $this->success('', [
                    'name' => (string)get_sys_config('weiwebs_name', ''),
                    'account' => (string)get_sys_config('weiwebs_account', ''),
                    'password' => (string)get_sys_config('weiwebs_password', ''),
                    'api_url' => (string)get_sys_config('weiwebs_api_url', ''),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 微网短信发送
     * @throws Throwable
     */
    public function weiWebsSmsSend(): void
    {
        try {
            if (!$this->request->isPost()) {
                $this->error('仅支持POST请求');
            }

            $mobile = (string)$this->request->post('mobile', '');
            $msg = (string)$this->request->post('msg', '');
            $needStatus = (bool)$this->request->post('needstatus', true);
            $product = (string)$this->request->post('product', '');
            $extno = (string)$this->request->post('extno', '');
            $useTimestamp = (bool)$this->request->post('use_timestamp', false);
            $jsonResponse = (bool)$this->request->post('json_response', false);

            if (empty($mobile)) {
                $this->error('手机号不能为空');
            }
            if (empty($msg)) {
                $this->error('短信内容不能为空');
            }

            $weiWebsSms = new WeiWebsSms();
            $result = $weiWebsSms->send(
                $mobile,
                $msg,
                $needStatus,
                $product ?: null,
                $extno ?: null,
                $useTimestamp,
                $jsonResponse
            );

            if ($result['code'] === 0) {
                $this->success($result['msg'], $result['data']);
            } else {
                $this->error($result['msg'], $result['data'], $result['code']);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存短信平台选择配置
     * @throws Throwable
     */
    public function smsPlatformConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $platform = (string)$this->request->post('platform', 'smsbao');
                
                // 验证平台值
                if (!in_array($platform, ['smsbao', 'weiwebs'])) {
                    $this->error('无效的短信平台，只能选择 smsbao 或 weiwebs');
                }

                $configName = 'sms_platform';
                $config = ConfigModel::where('name', $configName)->find();
                if ($config) {
                    ConfigModel::where('name', $configName)->update(['value' => $platform]);
                } else {
                    \think\facade\Db::name('config')->insert([
                        'name' => $configName,
                        'value' => $platform,
                        'title' => '短信平台选择',
                        'tip' => '选择使用的短信平台：smsbao（短信宝）或 weiwebs（麦讯通）',
                        'type' => 'string',
                        'group' => 'third',
                        'content' => '',
                        'rule' => '',
                        'extend' => '',
                        'allow_del' => 1,
                        'weigh' => 0,
                    ]);
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();
                $this->success('配置已更新');
            } else {
                $platform = (string)get_sys_config('sms_platform', 'smsbao');
                $this->success('', [
                    'platform' => $platform,
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存短信模板配置
     * @throws Throwable
     */
    public function smsTemplateConfig(): void
    {
        try {
            $configs = [
                'sms_template_smsbao'  => '【广州焦惠科技】您的验证码是1234。如非本人操作，请忽略本短信',
                'sms_template_weiwebs' => '【广州海佰电子商务】您的验证码是：1234，五分钟内有效！',
            ];

            if ($this->request->isPost()) {
                $smsbaoTemplate   = (string)$this->request->post('smsbao', $configs['sms_template_smsbao']);
                $weiwebsTemplate  = (string)$this->request->post('weiwebs', $configs['sms_template_weiwebs']);

                $pairs = [
                    'sms_template_smsbao'  => $smsbaoTemplate,
                    'sms_template_weiwebs' => $weiwebsTemplate,
                ];

                foreach ($pairs as $k => $v) {
                    $config = ConfigModel::where('name', $k)->find();
                    if ($config) {
                        ConfigModel::where('name', $k)->update(['value' => $v]);
                    } else {
                        \think\facade\Db::name('config')->insert([
                            'name'      => $k,
                            'value'     => $v,
                            'title'     => $k,
                            'tip'       => $k,
                            'type'      => 'string',
                            'group'     => 'third',
                            'content'   => '',
                            'rule'      => '',
                            'extend'    => '',
                            'allow_del' => 1,
                            'weigh'     => 0,
                        ]);
                    }
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();
                $this->success('配置已更新');
            } else {
                $this->success('', [
                    'smsbao'  => (string)get_sys_config('sms_template_smsbao', $configs['sms_template_smsbao']),
                    'weiwebs' => (string)get_sys_config('sms_template_weiwebs', $configs['sms_template_weiwebs']),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存消费金兑换绿色算力配置
     * @throws Throwable
     */
    public function scoreExchangeGreenPowerConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $scoreBase = (float)$this->request->post('score_base', 0);
                $greenPowerBase = (float)$this->request->post('green_power_base', 0);

                // 验证数据
                if ($scoreBase <= 0) {
                    $this->error('基准消费金必须大于0');
                }
                if ($greenPowerBase <= 0) {
                    $this->error('基准绿色算力必须大于0');
                }

                // 计算比例
                $exchangeRate = $scoreBase / $greenPowerBase;

                // 保存配置到系统配置表（保存基准值和计算出的比例）
                $configNames = [
                    'score_green_rate' => (string)$exchangeRate,
                    'score_green_score_base' => (string)$scoreBase,
                    'score_green_power_base' => (string)$greenPowerBase,
                ];

                foreach ($configNames as $configName => $configValue) {
                    $existing = ConfigModel::where('name', $configName)->field('id,name')->find();
                    if ($existing) {
                        ConfigModel::where('name', $configName)->update(['value' => $configValue]);
                    } else {
                        $titles = [
                            'score_green_rate' => '消费金兑换绿色算力比例',
                            'score_green_score_base' => '消费金兑换基准消费金',
                            'score_green_power_base' => '消费金兑换基准绿色算力',
                        ];
                        $tips = [
                            'score_green_rate' => '消费金兑换绿色算力的比例（自动计算）',
                            'score_green_score_base' => '消费金兑换的基准消费金值',
                            'score_green_power_base' => '消费金兑换的基准绿色算力值',
                        ];
                        \think\facade\Db::name('config')->insert([
                            'name' => $configName,
                            'value' => $configValue,
                            'title' => $titles[$configName] ?? '',
                            'tip' => $tips[$configName] ?? '',
                            'type' => 'number',
                            'group' => 'exchange',
                            'content' => '',
                            'rule' => 'required|numeric|gt:0',
                            'extend' => '',
                            'allow_del' => 1,
                            'weigh' => 0,
                        ]);
                    }
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('消费金兑换绿色算力配置更新成功');
            } else {
                $scoreBase = get_sys_config('score_green_score_base');
                $greenPowerBase = get_sys_config('score_green_power_base');
                $this->success('', [
                    'score_base' => $scoreBase !== null && $scoreBase !== '' ? (float)$scoreBase : 0,
                    'green_power_base' => $greenPowerBase !== null && $greenPowerBase !== '' ? (float)$greenPowerBase : 0,
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存算力权重比例配置
     * @throws Throwable
     */
    public function powerWeightRateConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $powerBase = (float)$this->request->post('power_base', 0);
                $weightBase = (float)$this->request->post('weight_base', 0);

                // 验证数据
                if ($powerBase <= 0) {
                    $this->error('基准算力必须大于0');
                }
                if ($weightBase <= 0) {
                    $this->error('基准权重必须大于0');
                }

                // 计算比例
                $powerWeightRate = $weightBase / $powerBase;

                // 保存配置到系统配置表（保存基准值和计算出的比例）
                $configNames = [
                    'power_weight_rate' => (string)$powerWeightRate,
                    'power_weight_power_base' => (string)$powerBase,
                    'power_weight_weight_base' => (string)$weightBase,
                ];

                foreach ($configNames as $configName => $configValue) {
                    $existing = ConfigModel::where('name', $configName)->field('id,name')->find();
                    if ($existing) {
                        ConfigModel::where('name', $configName)->update(['value' => $configValue]);
                    } else {
                        $titles = [
                            'power_weight_rate' => '算力权重比例',
                            'power_weight_power_base' => '算力权重基准算力',
                            'power_weight_weight_base' => '算力权重基准权重',
                        ];
                        $tips = [
                            'power_weight_rate' => '算力与权重的兑换比例（自动计算）',
                            'power_weight_power_base' => '算力权重的基准算力值',
                            'power_weight_weight_base' => '算力权重的基准权重值',
                        ];
                        \think\facade\Db::name('config')->insert([
                            'name' => $configName,
                            'value' => $configValue,
                            'title' => $titles[$configName] ?? '',
                            'tip' => $tips[$configName] ?? '',
                            'type' => 'number',
                            'group' => 'exchange',
                            'content' => '',
                            'rule' => 'required|numeric|gt:0',
                            'extend' => '',
                            'allow_del' => 1,
                            'weigh' => 0,
                        ]);
                    }
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('算力权重比例配置更新成功');
            } else {
                $powerBase = get_sys_config('power_weight_power_base');
                $weightBase = get_sys_config('power_weight_weight_base');
                $this->success('', [
                    'power_base' => $powerBase !== null && $powerBase !== '' ? (float)$powerBase : 0,
                    'weight_base' => $weightBase !== null && $weightBase !== '' ? (float)$weightBase : 0,
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取/保存直播视频配置
     * @throws Throwable
     */
    public function liveVideoConfig(): void
    {
        try {
            if ($this->request->isPost()) {
                $videoUrl = (string)$this->request->post('video_url', '');
                $title = (string)$this->request->post('title', '');
                $description = (string)$this->request->post('description', '');

                // 验证数据
                if (empty($videoUrl)) {
                    $this->error('视频地址不能为空');
                }

                // 基本URL格式验证
                if (!filter_var($videoUrl, FILTER_VALIDATE_URL)) {
                    $this->error('请输入有效的视频地址URL');
                }

                // 检查是否为mp4格式（如果提供了后缀）
                $pathInfo = pathinfo($videoUrl);
                if (isset($pathInfo['extension']) && strtolower($pathInfo['extension']) !== 'mp4') {
                    $this->error('目前只支持MP4格式的视频文件');
                }

                // 保存配置到系统配置表
                $configs = [
                    'live_video_url' => $videoUrl,
                    'live_video_title' => $title,
                    'live_video_description' => $description,
                ];

                foreach ($configs as $name => $value) {
                    $existing = ConfigModel::where('name', $name)->field('id,name')->find();
                    if ($existing) {
                        ConfigModel::where('name', $name)->update(['value' => $value]);
                    } else {
                        $titles = [
                            'live_video_url' => '直播视频地址',
                            'live_video_title' => '直播视频标题',
                            'live_video_description' => '直播视频描述',
                        ];
                        $tips = [
                            'live_video_url' => '直播视频的MP4文件地址',
                            'live_video_title' => '直播视频的标题显示',
                            'live_video_description' => '直播视频的详细描述',
                        ];
                        \think\facade\Db::name('config')->insert([
                            'name' => $name,
                            'value' => $value,
                            'title' => $titles[$name],
                            'tip' => $tips[$name],
                            'type' => 'string',
                            'group' => 'live',
                            'content' => '',
                            'rule' => '',
                            'extend' => '',
                            'allow_del' => 1,
                            'weigh' => 0,
                        ]);
                    }
                }

                Cache::tag(ConfigModel::$cacheTag)->clear();

                $this->success('直播视频配置更新成功');
            } else {
                $this->success('', [
                    'video_url' => (string)get_sys_config('live_video_url', ''),
                    'title' => (string)get_sys_config('live_video_title', ''),
                    'description' => (string)get_sys_config('live_video_description', ''),
                ]);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }
}

