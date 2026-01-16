<?php

namespace app\api\controller;

use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;
use think\facade\Db;
use Throwable;

#[Apidoc\Title("直播视频管理")]
class LiveVideo extends Frontend
{
    protected array $noNeedLogin = ['config'];
    protected array $noNeedPermission = ['*'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("获取直播视频配置"),
        Apidoc\Tag("直播视频"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/liveVideo/config"),
        Apidoc\Returned("video_url", type: "string", desc: "视频地址"),
        Apidoc\Returned("title", type: "string", desc: "视频标题"),
        Apidoc\Returned("description", type: "string", desc: "视频描述"),
        Apidoc\Returned("play_count", type: "int", desc: "播放量（去重用户数）"),
        Apidoc\Returned("user_played", type: "bool", desc: "当前用户是否已播放（需登录）")
    ]
    public function config(): void
    {
        $videoUrl = get_sys_config('live_video_url', '');
        $title = get_sys_config('live_video_title', '');
        $description = get_sys_config('live_video_description', '');

        // 统计总播放量（去重用户数）
        $playCount = (int)Db::name('live_video_play_record')
            ->field('DISTINCT user_id')
            ->count('user_id');

        $userPlayed = false;

        // 如果用户已登录，记录播放（每个用户只统计一次）
        if (isset($this->auth) && $this->auth && $this->auth->isLogin()) {
            $userId = $this->auth->id;
            
            // 检查用户是否已经播放过这个视频
            $existingRecord = Db::name('live_video_play_record')
                ->where('user_id', $userId)
                ->where('video_url', $videoUrl)
                ->find();

            if ($existingRecord) {
                $userPlayed = true;
            } else {
                // 记录新的播放
                try {
                    Db::name('live_video_play_record')->insert([
                        'user_id' => $userId,
                        'video_url' => $videoUrl,
                        'ip' => $this->request->ip(),
                        'user_agent' => $this->request->header('user-agent', ''),
                        'create_time' => time(),
                    ]);
                    $userPlayed = true;
                    // 更新播放量
                    $playCount = (int)Db::name('live_video_play_record')
                        ->field('DISTINCT user_id')
                        ->count('user_id');
                } catch (Throwable $e) {
                    // 如果插入失败（如重复），忽略错误，继续返回数据
                }
            }
        }

        $this->success('获取成功', [
            'video_url' => $videoUrl,
            'title' => $title,
            'description' => $description,
            'play_count' => $playCount,
            'user_played' => $userPlayed,
            'is_login' => isset($this->auth) && $this->auth && $this->auth->isLogin(),
        ]);
    }
}
