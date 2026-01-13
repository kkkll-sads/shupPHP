<?php

namespace app\api\controller;

use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;
use think\facade\Db;

#[Apidoc\Title("艺术家展示")]
class Artist extends Frontend
{
    // 列表和详情都不强制登录
    protected array $noNeedLogin = ['index', 'detail', 'workDetail', 'allWorks'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("艺术家列表"),
        Apidoc\Tag("艺术家"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/artist/index"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "10"),
        Apidoc\Returned("list", type: "array", desc: "艺术家列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "艺术家ID"),
        Apidoc\Returned("list[].name", type: "string", desc: "艺术家姓名"),
        Apidoc\Returned("list[].image", type: "string", desc: "头像完整URL"),
        Apidoc\Returned("list[].title", type: "string", desc: "头衔/职称"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
    ]
    public function index(): void
    {
        $page  = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $limit = min($limit, 50);

        $where = [
            ['status', '=', '1'],
        ];

        $list = Db::name('artist')
            ->where($where)
            ->field(['id', 'name', 'image', 'title'])
            ->order('sort desc, id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['image'] = $item['image'] ? full_url($item['image'], false) : '';
        }
        unset($item);

        $total = Db::name('artist')
            ->where($where)
            ->count();

        $this->success('', [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    #[
        Apidoc\Title("艺术家详情"),
        Apidoc\Tag("艺术家"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/artist/detail"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "艺术家ID"),
        Apidoc\Returned("id", type: "int", desc: "艺术家ID"),
        Apidoc\Returned("name", type: "string", desc: "姓名"),
        Apidoc\Returned("image", type: "string", desc: "头像完整URL"),
        Apidoc\Returned("title", type: "string", desc: "头衔/职称"),
        Apidoc\Returned("bio", type: "string", desc: "简介"),
        Apidoc\Returned("works", type: "array", desc: "代表作品列表"),
        Apidoc\Returned("works[].id", type: "int", desc: "作品ID"),
        Apidoc\Returned("works[].title", type: "string", desc: "作品名称"),
        Apidoc\Returned("works[].image", type: "string", desc: "作品图片完整URL"),
        Apidoc\Returned("works[].description", type: "string", desc: "作品描述"),
        Apidoc\Returned("works[].sort", type: "int", desc: "排序"),
        Apidoc\Returned("works[].status", type: "string", desc: "状态:0=隐藏,1=显示"),
    ]
    public function detail(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('参数错误');
        }

        $artist = Db::name('artist')
            ->where('id', $id)
            ->where('status', '1')
            ->find();

        if (!$artist) {
            $this->error('艺术家不存在或已禁用');
        }

        $artist['image'] = $artist['image'] ? full_url($artist['image'], false) : '';

        $works = Db::name('artist_work')
            ->where('artist_id', $id)
            ->where('status', '1')
            ->field(['id', 'artist_id', 'title', 'image', 'description', 'sort', 'status'])
            ->order('sort desc, id desc')
            ->select()
            ->toArray();

        foreach ($works as &$work) {
            $work['image'] = $work['image'] ? full_url($work['image'], false) : '';
        }
        unset($work);

        $artist['works'] = $works;

        $this->success('', $artist);
    }

    #[
        Apidoc\Title("作品详情"),
        Apidoc\Tag("艺术家"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/artist/workDetail"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "作品ID"),
        Apidoc\Returned("id", type: "int", desc: "作品ID"),
        Apidoc\Returned("artist_id", type: "int", desc: "艺术家ID"),
        Apidoc\Returned("title", type: "string", desc: "作品名称"),
        Apidoc\Returned("image", type: "string", desc: "作品图片完整URL"),
        Apidoc\Returned("description", type: "string", desc: "作品描述"),
        Apidoc\Returned("sort", type: "int", desc: "排序"),
        Apidoc\Returned("status", type: "string", desc: "状态:0=隐藏,1=显示"),
    ]
    public function workDetail(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('参数错误');
        }

        $work = Db::name('artist_work')
            ->where('id', $id)
            ->where('status', '1')
            ->find();

        if (!$work) {
            $this->error('作品不存在或已隐藏');
        }

        $work['image'] = $work['image'] ? full_url($work['image'], false) : '';

        $this->success('', $work);
    }

    #[
        Apidoc\Title("全部艺术家作品列表"),
        Apidoc\Tag("艺术家"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/artist/allWorks"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "10"),
        Apidoc\Returned("list", type: "array", desc: "作品列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "作品ID"),
        Apidoc\Returned("list[].artist_id", type: "int", desc: "艺术家ID"),
        Apidoc\Returned("list[].artist_name", type: "string", desc: "艺术家姓名"),
        Apidoc\Returned("list[].artist_title", type: "string", desc: "艺术家头衔"),
        Apidoc\Returned("list[].title", type: "string", desc: "作品名称"),
        Apidoc\Returned("list[].image", type: "string", desc: "作品图片完整URL"),
        Apidoc\Returned("list[].description", type: "string", desc: "作品描述"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
    ]
    public function allWorks(): void
    {
        $page  = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $limit = min($limit, 50);

        // 只展示启用的艺术家 + 显示中的作品
        $query = Db::name('artist_work')
            ->alias('w')
            ->join('artist a', 'w.artist_id = a.id')
            ->where('w.status', '1')
            ->where('a.status', '1');

        $total = (clone $query)->count();

        $list = $query
            ->field([
                'w.id',
                'w.artist_id',
                'a.name as artist_name',
                'a.title as artist_title',
                'w.title',
                'w.image',
                'w.description',
                'w.sort',
            ])
            ->order('w.sort desc, w.id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['image'] = $item['image'] ? full_url($item['image'], false) : '';
        }
        unset($item);

        $this->success('', [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }
}


