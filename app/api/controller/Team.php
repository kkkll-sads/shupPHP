<?php

namespace app\api\controller;

use think\facade\Db;
use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("我的团队")]
class Team extends Frontend
{
    protected array $noNeedLogin = ['qrcode'];
    protected array $noNeedPermission = [];

    #[
        Apidoc\Title("我的团队概览"),
        Apidoc\Tag("团队,推广"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Team/overview"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data.balance", type: "float", desc: "可提现余额"),
        Apidoc\Returned("data.total_money", type: "float", desc: "总余额（充值金额）"),
        Apidoc\Returned("data.usdt", type: "float", desc: "USDT余额"),
        Apidoc\Returned("data.static_income", type: "float", desc: "静态收益"),
        Apidoc\Returned("data.dynamic_income", type: "float", desc: "服务金额"),
        Apidoc\Returned("data.invite_code", type: "string", desc: "邀请码"),
        Apidoc\Returned("data.invite_link", type: "string", desc: "邀请链接"),
        Apidoc\Returned("data.qrcode_url", type: "string", desc: "推广二维码URL"),
        Apidoc\Returned("data.team_total", type: "int", desc: "团队总人数"),
        Apidoc\Returned("data.today_register", type: "int", desc: "今日注册人数"),
        Apidoc\Returned("data.big_area_performance", type: "float", desc: "大区业绩"),
        Apidoc\Returned("data.small_area_performance", type: "float", desc: "小区总业绩"),
        Apidoc\Returned("data.level1_count", type: "int", desc: "一级直推人数"),
        Apidoc\Returned("data.level2_count", type: "int", desc: "二级间推人数"),
        Apidoc\Returned("data.level3_count", type: "int", desc: "三级人数"),
    ]
    public function overview()
    {
        try {
            $userId = $this->auth->id;

            // 获取用户基本信息
            $user = Db::name('user')->where('id', $userId)->find();
            if (!$user) {
                return json(['code' => -1, 'msg' => '用户不存在']);
            }

            // 获取用户的邀请码
            $inviteCodeInfo = Db::name('invite_code')->where('user_id', $userId)->find();
            $inviteCode = $inviteCodeInfo['code'] ?? '';

            // 生成邀请链接
            $domain = request()->domain();
            $inviteLink = $domain . '/register?invite_code=' . $inviteCode;

            // 生成二维码URL（这里使用在线二维码API，也可以使用本地二维码库）
            $qrcodeUrl = $domain . '/api/Team/qrcode?code=' . $inviteCode;

            // 获取一级直推用户ID列表
            $level1Users = Db::name('user')
                ->where('inviter_id', $userId)
                ->column('id');
            $level1Count = count($level1Users);

            // 获取二级间推用户ID列表
            $level2Users = [];
            if (!empty($level1Users)) {
                $level2Users = Db::name('user')
                    ->whereIn('inviter_id', $level1Users)
                    ->column('id');
            }
            $level2Count = count($level2Users);

            // 获取三级用户ID列表
            $level3Users = [];
            if (!empty($level2Users)) {
                $level3Users = Db::name('user')
                    ->whereIn('inviter_id', $level2Users)
                    ->column('id');
            }
            $level3Count = count($level3Users);

            // 团队总人数
            $teamTotal = $level1Count + $level2Count + $level3Count;

            // 今日注册人数（所有团队成员中今日注册的）
            $allTeamUserIds = array_merge($level1Users, $level2Users, $level3Users);
            $todayStart = strtotime(date('Y-m-d 00:00:00'));
            $todayRegister = 0;
            if (!empty($allTeamUserIds)) {
                $todayRegister = Db::name('user')
                    ->whereIn('id', $allTeamUserIds)
                    ->where('join_time', '>=', $todayStart)
                    ->count();
            }

            // 计算大区业绩和小区业绩
            // 大区业绩：所有下级的业绩总和
            // 小区业绩：取下级中业绩最高的作为大区，其余为小区
            $level1Performance = $this->calculateUserPerformance($level1Users);
            
            // 找出业绩最高的一级用户（大区）
            $bigAreaPerformance = 0;
            $smallAreaPerformance = 0;
            
            if (!empty($level1Performance)) {
                // 按业绩排序
                arsort($level1Performance);
                $performances = array_values($level1Performance);
                
                // 第一名为大区业绩
                $bigAreaPerformance = $performances[0] ?? 0;
                
                // 其余为小区业绩总和
                $smallAreaPerformance = array_sum(array_slice($performances, 1));
            }

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'balance' => (float)$user['withdrawable_money'],
                    'total_money' => (float)$user['money'],
                    'usdt' => (float)($user['usdt'] ?? 0),
                    'static_income' => (float)($user['static_income'] ?? 0),
                    'dynamic_income' => (float)($user['dynamic_income'] ?? 0),
                    'invite_code' => $inviteCode,
                    'invite_link' => $inviteLink,
                    'qrcode_url' => $qrcodeUrl,
                    'team_total' => $teamTotal,
                    'today_register' => (int)$todayRegister,
                    'big_area_performance' => (float)$bigAreaPerformance,
                    'small_area_performance' => (float)$smallAreaPerformance,
                    'level1_count' => $level1Count,
                    'level2_count' => $level2Count,
                    'level3_count' => $level3Count,
                ]
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    /**
     * 计算用户业绩（该用户及其所有下级的消费总和）
     * @param array $userIds 用户ID数组
     * @return array 返回每个用户的业绩数组 [用户ID => 业绩]
     */
    private function calculateUserPerformance(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $performances = [];
        
        foreach ($userIds as $uid) {
            // 获取该用户的所有下级
            $allSubUserIds = $this->getAllSubUsers($uid);
            $allSubUserIds[] = $uid; // 包含自己
            
            // 计算这些用户的总消费（这里以订单金额为例，根据实际业务调整）
            $totalPerformance = 0;
            
            // 统计理财产品订单金额
            $financeAmount = Db::name('finance_order')
                ->whereIn('user_id', $allSubUserIds)
                ->where('status', 'completed')
                ->sum('amount');
            $totalPerformance += (float)$financeAmount;
            
            // 统计商城订单金额
            $shopAmount = Db::name('shop_order')
                ->whereIn('user_id', $allSubUserIds)
                ->whereIn('status', ['paid', 'shipped', 'completed'])
                ->sum('total_amount');
            $totalPerformance += (float)$shopAmount;
            
            $performances[$uid] = $totalPerformance;
        }
        
        return $performances;
    }

    /**
     * 获取用户的所有下级（递归获取所有层级）
     * @param int $userId 用户ID
     * @param int $maxLevel 最大层级，默认3级
     * @return array 所有下级用户ID数组
     */
    private function getAllSubUsers(int $userId, int $maxLevel = 3): array
    {
        $allSubUsers = [];
        $this->getSubUsersRecursive($userId, $allSubUsers, 1, $maxLevel);
        return $allSubUsers;
    }

    /**
     * 递归获取下级用户
     */
    private function getSubUsersRecursive(int $userId, array &$result, int $currentLevel, int $maxLevel): void
    {
        if ($currentLevel > $maxLevel) {
            return;
        }

        $subUsers = Db::name('user')
            ->where('inviter_id', $userId)
            ->column('id');

        if (!empty($subUsers)) {
            $result = array_merge($result, $subUsers);
            foreach ($subUsers as $subUserId) {
                $this->getSubUsersRecursive($subUserId, $result, $currentLevel + 1, $maxLevel);
            }
        }
    }

    #[
        Apidoc\Title("团队成员列表"),
        Apidoc\Tag("团队,推广"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Team/members"),
        Apidoc\Query(name: "level", type: "int", require: false, desc: "层级：1=一级直推，2=二级间推，3=三级，不传则返回所有", example: "1"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", example: "1"),
        Apidoc\Query(name: "page_size", type: "int", require: false, desc: "每页数量", example: "10"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data.total", type: "int", desc: "总记录数"),
        Apidoc\Returned("data.page", type: "int", desc: "当前页码"),
        Apidoc\Returned("data.page_size", type: "int", desc: "每页数量"),
        Apidoc\Returned("data.list", type: "array", desc: "成员列表"),
    ]
    public function members()
    {
        try {
            $userId = $this->auth->id;
            $level = (int)$this->request->get('level', 0);
            $page = max(1, (int)$this->request->get('page', 1));
            $pageSize = max(1, min(100, (int)$this->request->get('page_size', 10)));

            // 根据层级获取用户ID列表
            $userIds = $this->getMembersByLevel($userId, $level);

            $total = count($userIds);
            
            // 分页
            $offset = ($page - 1) * $pageSize;
            $pageUserIds = array_slice($userIds, $offset, $pageSize);

            $list = [];
            if (!empty($pageUserIds)) {
                $members = Db::name('user')
                    ->whereIn('id', $pageUserIds)
                    ->field('id,nickname,avatar,mobile,join_time,real_name,real_name_status')
                    ->select()
                    ->toArray();

                foreach ($members as $member) {
                    // 获取该成员相对于当前用户的层级
                    $memberLevel = $this->getUserLevel($userId, $member['id']);
                    $levelText = match($memberLevel) {
                        1 => '一级 直推',
                        2 => '二级 间推',
                        3 => '三级',
                        default => '未知'
                    };
                    
                    // 处理实名显示：已实名显示脱敏姓名，未实名显示"未实名"
                    $displayName = '未实名';
                    if (!empty($member['real_name_status']) && !empty($member['real_name'])) {
                        $displayName = $this->maskRealName($member['real_name']);
                    }
                    
                    $list[] = [
                        'id' => $member['id'],
                        'username' => $displayName,  // 显示实名（脱敏）
                        'nickname' => $member['nickname'],
                        'avatar' => full_url($member['avatar'], false),
                        'mobile' => $this->maskMobile($member['mobile']),
                        'register_time' => date('Y-m-d H:i:s', $member['join_time']),
                        'level' => $memberLevel,
                        'level_text' => $levelText,
                    ];
                }
            }

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'list' => $list,
                ]
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    /**
     * 根据层级获取成员ID列表
     */
    private function getMembersByLevel(int $userId, int $level): array
    {
        if ($level == 1) {
            // 一级直推
            return Db::name('user')->where('inviter_id', $userId)->column('id');
        } elseif ($level == 2) {
            // 二级间推
            $level1Users = Db::name('user')->where('inviter_id', $userId)->column('id');
            if (empty($level1Users)) {
                return [];
            }
            return Db::name('user')->whereIn('inviter_id', $level1Users)->column('id');
        } elseif ($level == 3) {
            // 三级
            $level1Users = Db::name('user')->where('inviter_id', $userId)->column('id');
            if (empty($level1Users)) {
                return [];
            }
            $level2Users = Db::name('user')->whereIn('inviter_id', $level1Users)->column('id');
            if (empty($level2Users)) {
                return [];
            }
            return Db::name('user')->whereIn('inviter_id', $level2Users)->column('id');
        } else {
            // 所有层级
            $level1Users = Db::name('user')->where('inviter_id', $userId)->column('id');
            $level2Users = [];
            $level3Users = [];
            
            if (!empty($level1Users)) {
                $level2Users = Db::name('user')->whereIn('inviter_id', $level1Users)->column('id');
            }
            if (!empty($level2Users)) {
                $level3Users = Db::name('user')->whereIn('inviter_id', $level2Users)->column('id');
            }
            
            return array_merge($level1Users, $level2Users, $level3Users);
        }
    }

    /**
     * 获取某个用户的团队人数
     */
    private function getTeamCount(int $userId): int
    {
        $allSubUsers = $this->getAllSubUsers($userId);
        return count($allSubUsers);
    }

    /**
     * 手机号脱敏
     */
    private function maskMobile(string $mobile): string
    {
        if (strlen($mobile) == 11) {
            return substr($mobile, 0, 3) . '****' . substr($mobile, 7);
        }
        return $mobile;
    }

    /**
     * 真实姓名脱敏
     * 2个字：保留第一个字，第二个字用*代替，如：张三 -> 张*
     * 3个字及以上：保留第一个字和最后一个字，中间用*代替，如：张小三 -> 张*三，欧阳修 -> 欧*修
     */
    private function maskRealName(string $realName): string
    {
        if (empty($realName)) {
            return '未实名';
        }
        
        $length = mb_strlen($realName, 'UTF-8');
        
        if ($length == 1) {
            return $realName;
        } elseif ($length == 2) {
            return mb_substr($realName, 0, 1, 'UTF-8') . '*';
        } else {
            // 3个字及以上：保留首尾，中间用*
            $first = mb_substr($realName, 0, 1, 'UTF-8');
            $last = mb_substr($realName, -1, 1, 'UTF-8');
            $middle = str_repeat('*', $length - 2);
            return $first . $middle . $last;
        }
    }

    #[
        Apidoc\Title("今日推荐列表"),
        Apidoc\Tag("团队,推广"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Team/todayRecommend"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", example: "1"),
        Apidoc\Query(name: "page_size", type: "int", require: false, desc: "每页数量", example: "10"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data.total", type: "int", desc: "总记录数"),
        Apidoc\Returned("data.page", type: "int", desc: "当前页码"),
        Apidoc\Returned("data.page_size", type: "int", desc: "每页数量"),
        Apidoc\Returned("data.list", type: "array", desc: "今日推荐列表"),
    ]
    public function todayRecommend()
    {
        try {
            $userId = $this->auth->id;
            $page = max(1, (int)$this->request->get('page', 1));
            $pageSize = max(1, min(100, (int)$this->request->get('page_size', 10)));

            // 今日开始时间戳
            $todayStart = strtotime(date('Y-m-d 00:00:00'));

            // 获取所有团队成员中今日注册的（包括所有层级）
            $allTeamUserIds = $this->getMembersByLevel($userId, 0);

            $query = Db::name('user')
                ->where('join_time', '>=', $todayStart)
                ->field('id,username,nickname,avatar,mobile,join_time,inviter_id');

            if (!empty($allTeamUserIds)) {
                $query->whereIn('id', $allTeamUserIds);
            } else {
                // 如果没有团队成员，返回空列表
                return json([
                    'code' => 0,
                    'msg' => 'success',
                    'data' => [
                        'total' => 0,
                        'page' => $page,
                        'page_size' => $pageSize,
                        'list' => [],
                    ]
                ]);
            }

            $total = $query->count();
            $members = $query->order('join_time', 'desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            $list = [];
            foreach ($members as $member) {
                // 判断是直推还是间推
                $level = $this->getUserLevel($userId, $member['id']);
                $levelText = $level == 1 ? '一级 直推' : ($level == 2 ? '二级 间推' : '三级');

                $list[] = [
                    'id' => $member['id'],
                    'username' => $member['username'],
                    'nickname' => $member['nickname'],
                    'avatar' => full_url($member['avatar'], false),
                    'mobile' => $this->maskMobile($member['mobile']),
                    'register_time' => date('Y-m-d H:i:s', $member['join_time']),
                    'level' => $level,
                    'level_text' => $levelText,
                ];
            }

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'list' => $list,
                ]
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    /**
     * 获取用户相对于指定用户的层级
     */
    private function getUserLevel(int $topUserId, int $targetUserId): int
    {
        // 检查是否是一级
        $level1 = Db::name('user')->where('id', $targetUserId)->where('inviter_id', $topUserId)->find();
        if ($level1) {
            return 1;
        }

        // 检查是否是二级
        $level1Users = Db::name('user')->where('inviter_id', $topUserId)->column('id');
        if (!empty($level1Users)) {
            $level2 = Db::name('user')->where('id', $targetUserId)->whereIn('inviter_id', $level1Users)->find();
            if ($level2) {
                return 2;
            }

            // 检查是否是三级
            $level2Users = Db::name('user')->whereIn('inviter_id', $level1Users)->column('id');
            if (!empty($level2Users)) {
                $level3 = Db::name('user')->where('id', $targetUserId)->whereIn('inviter_id', $level2Users)->find();
                if ($level3) {
                    return 3;
                }
            }
        }

        return 0;
    }

    #[
        Apidoc\Title("生成推广二维码"),
        Apidoc\Tag("团队,推广"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Team/qrcode"),
        Apidoc\Query(name: "code", type: "string", require: true, desc: "邀请码", example: "ABC123"),
    ]
    public function qrcode()
    {
        try {
            $code = $this->request->get('code', '');
            if (empty($code)) {
                return json(['code' => -1, 'msg' => '邀请码不能为空']);
            }

            // 生成邀请链接
            $domain = request()->domain();
            $inviteLink = $domain . '/register?invite_code=' . $code;

            // 使用第三方二维码API生成二维码
            // 这里使用一个免费的二维码API服务
            $qrcodeApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($inviteLink);

            // 重定向到二维码图片
            header('Location: ' . $qrcodeApiUrl);
            exit;
        } catch (\Throwable $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    #[
        Apidoc\Title("推广名片信息"),
        Apidoc\Tag("团队,推广"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Team/promotionCard"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data.user_info", type: "object", desc: "用户信息"),
        Apidoc\Returned("data.invite_code", type: "string", desc: "邀请码"),
        Apidoc\Returned("data.invite_link", type: "string", desc: "邀请链接"),
        Apidoc\Returned("data.qrcode_url", type: "string", desc: "二维码URL"),
        Apidoc\Returned("data.team_count", type: "int", desc: "团队人数"),
        Apidoc\Returned("data.total_performance", type: "float", desc: "总业绩"),
    ]
    public function promotionCard()
    {
        try {
            $userId = $this->auth->id;

            // 获取用户信息
            $user = Db::name('user')
                ->where('id', $userId)
                ->field('id,username,nickname,avatar,mobile')
                ->find();

            if (!$user) {
                return json(['code' => -1, 'msg' => '用户不存在']);
            }

            // 获取邀请码
            $inviteCodeInfo = Db::name('invite_code')->where('user_id', $userId)->find();
            $inviteCode = $inviteCodeInfo['code'] ?? '';

            // 生成邀请链接
            $domain = request()->domain();
            $inviteLink = $domain . '/register?invite_code=' . $inviteCode;
            $qrcodeUrl = $domain . '/api/Team/qrcode?code=' . $inviteCode;

            // 获取团队人数
            $allTeamUserIds = $this->getMembersByLevel($userId, 0);
            $teamCount = count($allTeamUserIds);

            // 计算总业绩
            $allUserIds = array_merge([$userId], $allTeamUserIds);
            $performances = $this->calculateUserPerformance($allUserIds);
            $totalPerformance = array_sum($performances);

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'user_info' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'nickname' => $user['nickname'],
                        'avatar' => full_url($user['avatar'], false),
                        'mobile' => $this->maskMobile($user['mobile']),
                    ],
                    'invite_code' => $inviteCode,
                    'invite_link' => $inviteLink,
                    'qrcode_url' => $qrcodeUrl,
                    'team_count' => $teamCount,
                    'total_performance' => (float)$totalPerformance,
                ]
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }
}

