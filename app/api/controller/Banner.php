<?php

namespace app\api\controller;

use Throwable;
use app\common\controller\Frontend;
use app\admin\model\Banner as BannerModel;

use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("轮番图管理")]
class Banner extends Frontend
{
    protected array $noNeedLogin = ['getBannerList', 'getBannerDetail'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("获取轮番图列表"),
        Apidoc\Tag("轮番图"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Banner/getBannerList"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Returned("list", type: "array", desc: "轮番图列表"),
        Apidoc\Returned("total", type: "int", desc: "总数量"),
        Apidoc\Returned("current_page", type: "int", desc: "当前页"),
        Apidoc\Returned("last_page", type: "int", desc: "最后一页"),
    ]
    /**
     * 获取轮番图列表
     * @throws Throwable
     */
    public function getBannerList(): void
    {
        $model = new BannerModel();

        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);

        $where = [
            ['status', '=', '1'],
        ];

        // 时间筛选：显示当前时间范围内有效的轮番图
        $now = time();
        // 开始时间：为空（0, null, ''）或小于等于当前时间
        $where[] = function ($query) use ($now) {
            $query->where(function ($q) {
                $q->where('start_time', '=', 0)
                  ->whereOr('start_time', '=', null)
                  ->whereOr('start_time', '=', '');
            })->whereOr('start_time', '<=', $now);
        };
        // 结束时间：为空（0, null, ''）或大于等于当前时间
        $where[] = function ($query) use ($now) {
            $query->where(function ($q) {
                $q->where('end_time', '=', 0)
                  ->whereOr('end_time', '=', null)
                  ->whereOr('end_time', '=', '');
            })->whereOr('end_time', '>=', $now);
        };

        $result = $model
            ->where($where)
            ->order('sort desc, id desc')
            ->paginate($limit, false, ['page' => $page]);

        $this->success('', [
            'list' => $result->items(),
            'total' => $result->total(),
            'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage(),
        ]);
    }

    #[
        Apidoc\Title("获取轮番图详情"),
        Apidoc\Tag("轮番图"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Banner/getBannerDetail"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "轮番图ID"),
        Apidoc\Returned("banner", type: "object", desc: "轮番图详情"),
        Apidoc\Returned("banner.id", type: "int", desc: "轮番图ID"),
        Apidoc\Returned("banner.title", type: "string", desc: "标题"),
        Apidoc\Returned("banner.image", type: "string", desc: "图片URL"),
        Apidoc\Returned("banner.url", type: "string", desc: "跳转链接"),
        Apidoc\Returned("banner.description", type: "string", desc: "描述"),
        Apidoc\Returned("banner.sort", type: "int", desc: "排序"),
        Apidoc\Returned("banner.status", type: "string", desc: "状态"),
        Apidoc\Returned("banner.start_time", type: "string", desc: "开始时间"),
        Apidoc\Returned("banner.end_time", type: "string", desc: "结束时间"),
    ]
    /**
     * 获取轮番图详情
     * @throws Throwable
     */
    public function getBannerDetail(): void
    {
        $id = $this->request->get('id/d', 0);

        if (!$id) {
            $this->error('轮番图ID不能为空');
        }

        $model = new BannerModel();
        $banner = $model->find($id);

        if (!$banner) {
            $this->error('轮番图不存在');
        }

        $this->success('', [
            'banner' => $banner,
        ]);
    }

}
