<?php

namespace app\api\controller;

use Throwable;
use app\common\controller\Frontend;
use app\admin\model\ContentHotVideo as ContentHotVideoModel;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("热门视频管理")]
class ContentHotVideo extends Frontend
{
    protected array $noNeedLogin = ['index', 'detail', 'hotList'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("获取热门视频列表"),
        Apidoc\Tag("热门视频"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/ContentHotVideo/index"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1", mock: "@integer(1, 100)"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10", mock: "@integer(1, 100)"),
        Apidoc\Query(name: "title", type: "string", require: false, desc: "标题关键词", default: "", mock: "@ctitle(2, 10)"),
        Apidoc\Returned("list", type: "array", desc: "热门视频列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "视频ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "视频标题"),
        Apidoc\Returned("list[].summary", type: "string", desc: "视频摘要"),
        Apidoc\Returned("list[].video_url", type: "string", desc: "视频地址URL"),
        Apidoc\Returned("list[].cover_image", type: "string", desc: "封面图片URL"),
        Apidoc\Returned("list[].status", type: "string", desc: "状态"),
        Apidoc\Returned("list[].publish_time", type: "int", desc: "发布时间戳"),
        Apidoc\Returned("list[].sort", type: "int", desc: "排序"),
        Apidoc\Returned("list[].view_count", type: "int", desc: "浏览量"),
        Apidoc\Returned("list[].create_time", type: "int", desc: "创建时间戳"),
        Apidoc\Returned("total", type: "int", desc: "总数量"),
        Apidoc\Returned("current_page", type: "int", desc: "当前页"),
        Apidoc\Returned("last_page", type: "int", desc: "最后一页"),
    ]
    /**
     * 获取热门视频列表
     * @throws Throwable
     */
    public function index(): void
    {
        $model = new ContentHotVideoModel();

        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);
        $title = $this->request->get('title', '');

        $where = [
            ['status', '=', '1'], // 只显示已启用的视频
        ];

        // 标题搜索
        if ($title) {
            $where[] = ['title', 'like', '%' . $title . '%'];
        }

        // 只显示已发布的视频（发布时间小于等于当前时间）
        $now = time();
        $where[] = function ($query) use ($now) {
            $query->where(function ($q) {
                $q->where('publish_time', '=', 0)
                  ->whereOr('publish_time', '=', null)
                  ->whereOr('publish_time', '=', '');
            })->whereOr('publish_time', '<=', $now);
        };

        $result = $model
            ->field('id,title,summary,video_url,cover_image,status,publish_time,sort,view_count,create_time')
            ->where($where)
            ->order('sort desc, publish_time desc, id desc')
            ->paginate($limit, false, ['page' => $page]);

        $this->success('获取成功', [
            'list' => $result->items(),
            'total' => $result->total(),
            'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage(),
        ]);
    }

    #[
        Apidoc\Title("获取热门视频详情"),
        Apidoc\Tag("热门视频"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/ContentHotVideo/detail"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "视频ID", mock: "@integer(1, 100)"),
        Apidoc\Returned("video", type: "object", desc: "热门视频详情"),
        Apidoc\Returned("video.id", type: "int", desc: "视频ID"),
        Apidoc\Returned("video.title", type: "string", desc: "视频标题"),
        Apidoc\Returned("video.summary", type: "string", desc: "视频摘要"),
        Apidoc\Returned("video.video_url", type: "string", desc: "视频地址URL"),
        Apidoc\Returned("video.cover_image", type: "string", desc: "封面图片URL"),
        Apidoc\Returned("video.status", type: "string", desc: "状态"),
        Apidoc\Returned("video.publish_time", type: "int", desc: "发布时间戳"),
        Apidoc\Returned("video.sort", type: "int", desc: "排序"),
        Apidoc\Returned("video.view_count", type: "int", desc: "浏览量"),
        Apidoc\Returned("video.create_time", type: "int", desc: "创建时间戳"),
        Apidoc\Returned("video.update_time", type: "int", desc: "更新时间戳"),
    ]
    /**
     * 获取热门视频详情
     * @throws Throwable
     */
    public function detail(): void
    {
        $id = $this->request->get('id/d', 0);

        if (!$id) {
            $this->error('视频ID不能为空');
        }

        $model = new ContentHotVideoModel();
        $video = $model->find($id);

        if (!$video) {
            $this->error('视频不存在');
        }

        if ($video->status != '1') {
            $this->error('视频已下架');
        }

        // 增加浏览量
        $video->view_count = $video->view_count + 1;
        $video->save();

        $this->success('获取成功', [
            'video' => $video,
        ]);
    }

    #[
        Apidoc\Title("获取热门视频列表（限制数量）"),
        Apidoc\Tag("热门视频"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/ContentHotVideo/hotList"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "数量限制", default: "5", mock: "@integer(1, 20)"),
        Apidoc\Returned("list", type: "array", desc: "热门视频列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "视频ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "视频标题"),
        Apidoc\Returned("list[].summary", type: "string", desc: "视频摘要"),
        Apidoc\Returned("list[].video_url", type: "string", desc: "视频地址URL"),
        Apidoc\Returned("list[].cover_image", type: "string", desc: "封面图片URL"),
        Apidoc\Returned("list[].view_count", type: "int", desc: "浏览量"),
        Apidoc\Returned("list[].publish_time", type: "int", desc: "发布时间戳"),
    ]
    /**
     * 获取热门视频列表（限制数量）
     * @throws Throwable
     */
    public function hotList(): void
    {
        $model = new ContentHotVideoModel();
        $limit = $this->request->get('limit/d', 5);

        $where = [
            ['status', '=', '1'],
        ];

        // 只显示已发布的视频
        $now = time();
        $where[] = function ($query) use ($now) {
            $query->where(function ($q) {
                $q->where('publish_time', '=', 0)
                  ->whereOr('publish_time', '=', null)
                  ->whereOr('publish_time', '=', '');
            })->whereOr('publish_time', '<=', $now);
        };

        $list = $model
            ->field('id,title,summary,video_url,cover_image,view_count,publish_time')
            ->where($where)
            ->order('sort desc, view_count desc, id desc')
            ->limit($limit)
            ->select();

        $this->success('获取成功', [
            'list' => $list,
        ]);
    }
}

