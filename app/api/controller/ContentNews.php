<?php

namespace app\api\controller;

use Throwable;
use app\common\controller\Frontend;
use app\admin\model\ContentNews as ContentNewsModel;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("资讯管理")]
class ContentNews extends Frontend
{
    protected array $noNeedLogin = ['index', 'detail', 'hotList'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("获取资讯列表"),
        Apidoc\Tag("资讯"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/ContentNews/index"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1", mock: "@integer(1, 100)"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10", mock: "@integer(1, 100)"),
        Apidoc\Query(name: "is_hot", type: "int", require: false, desc: "是否热门：1=是,0=否", default: "", mock: "@pick([0, 1])"),
        Apidoc\Query(name: "title", type: "string", require: false, desc: "标题关键词", default: "", mock: "@ctitle(2, 10)"),
        Apidoc\Returned("list", type: "array", desc: "资讯列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "资讯ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "资讯标题"),
        Apidoc\Returned("list[].summary", type: "string", desc: "资讯摘要"),
        Apidoc\Returned("list[].cover_image", type: "string", desc: "封面图片URL"),
        Apidoc\Returned("list[].link_url", type: "string", desc: "跳转链接"),
        Apidoc\Returned("list[].is_hot", type: "int", desc: "是否热门"),
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
     * 获取资讯列表
     * @throws Throwable
     */
    public function index(): void
    {
        $model = new ContentNewsModel();

        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);
        $isHot = $this->request->get('is_hot', '');
        $title = $this->request->get('title', '');

        $where = [
            ['status', '=', '1'], // 只显示已启用的资讯
        ];

        // 热门筛选
        if ($isHot !== '') {
            $where[] = ['is_hot', '=', $isHot];
        }

        // 标题搜索
        if ($title) {
            $where[] = ['title', 'like', '%' . $title . '%'];
        }

        // 只显示已发布的资讯（发布时间小于等于当前时间）
        $now = time();
        $where[] = function ($query) use ($now) {
            $query->where(function ($q) {
                $q->where('publish_time', '=', 0)
                  ->whereOr('publish_time', '=', null)
                  ->whereOr('publish_time', '=', '');
            })->whereOr('publish_time', '<=', $now);
        };

        $result = $model
            ->field('id,title,summary,cover_image,link_url,is_hot,status,publish_time,sort,view_count,create_time')
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
        Apidoc\Title("获取资讯详情"),
        Apidoc\Tag("资讯"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/ContentNews/detail"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "资讯ID", mock: "@integer(1, 100)"),
        Apidoc\Returned("news", type: "object", desc: "资讯详情"),
        Apidoc\Returned("news.id", type: "int", desc: "资讯ID"),
        Apidoc\Returned("news.title", type: "string", desc: "资讯标题"),
        Apidoc\Returned("news.summary", type: "string", desc: "资讯摘要"),
        Apidoc\Returned("news.cover_image", type: "string", desc: "封面图片URL"),
        Apidoc\Returned("news.content", type: "string", desc: "资讯内容"),
        Apidoc\Returned("news.link_url", type: "string", desc: "跳转链接"),
        Apidoc\Returned("news.is_hot", type: "int", desc: "是否热门"),
        Apidoc\Returned("news.status", type: "string", desc: "状态"),
        Apidoc\Returned("news.publish_time", type: "int", desc: "发布时间戳"),
        Apidoc\Returned("news.sort", type: "int", desc: "排序"),
        Apidoc\Returned("news.view_count", type: "int", desc: "浏览量"),
        Apidoc\Returned("news.create_time", type: "int", desc: "创建时间戳"),
        Apidoc\Returned("news.update_time", type: "int", desc: "更新时间戳"),
    ]
    /**
     * 获取资讯详情
     * @throws Throwable
     */
    public function detail(): void
    {
        $id = $this->request->get('id/d', 0);

        if (!$id) {
            $this->error('资讯ID不能为空');
        }

        $model = new ContentNewsModel();
        $news = $model->find($id);

        if (!$news) {
            $this->error('资讯不存在');
        }

        if ($news->status != '1') {
            $this->error('资讯已下架');
        }

        // 增加浏览量
        $news->view_count = $news->view_count + 1;
        $news->save();

        $this->success('获取成功', [
            'news' => $news,
        ]);
    }

    #[
        Apidoc\Title("获取热门资讯列表"),
        Apidoc\Tag("资讯"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/ContentNews/hotList"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "数量限制", default: "5", mock: "@integer(1, 20)"),
        Apidoc\Returned("list", type: "array", desc: "热门资讯列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "资讯ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "资讯标题"),
        Apidoc\Returned("list[].summary", type: "string", desc: "资讯摘要"),
        Apidoc\Returned("list[].cover_image", type: "string", desc: "封面图片URL"),
        Apidoc\Returned("list[].view_count", type: "int", desc: "浏览量"),
        Apidoc\Returned("list[].publish_time", type: "int", desc: "发布时间戳"),
    ]
    /**
     * 获取热门资讯列表
     * @throws Throwable
     */
    public function hotList(): void
    {
        $model = new ContentNewsModel();
        $limit = $this->request->get('limit/d', 5);

        $where = [
            ['status', '=', '1'],
            ['is_hot', '=', 1],
        ];

        // 只显示已发布的资讯
        $now = time();
        $where[] = function ($query) use ($now) {
            $query->where(function ($q) {
                $q->where('publish_time', '=', 0)
                  ->whereOr('publish_time', '=', null)
                  ->whereOr('publish_time', '=', '');
            })->whereOr('publish_time', '<=', $now);
        };

        $list = $model
            ->field('id,title,summary,cover_image,view_count,publish_time')
            ->where($where)
            ->order('sort desc, view_count desc, id desc')
            ->limit($limit)
            ->select();

        $this->success('获取成功', [
            'list' => $list,
        ]);
    }
}

