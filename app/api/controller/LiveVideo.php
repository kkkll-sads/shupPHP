<?php

namespace app\api\controller;

use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;
use think\exception\HttpResponseException;

#[Apidoc\Title("直播视频管理")]
class LiveVideo extends Frontend
{
    protected array $noNeedLogin = ['config'];

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
        Apidoc\Returned("description", type: "string", desc: "视频描述")
    ]
    public function config(): void
    {
        try {
            $videoUrl = get_sys_config('live_video_url', '');
            $title = get_sys_config('live_video_title', '');
            $description = get_sys_config('live_video_description', '');

            $this->success('', [
                'video_url' => $videoUrl,
                'title' => $title,
                'description' => $description,
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }
}
