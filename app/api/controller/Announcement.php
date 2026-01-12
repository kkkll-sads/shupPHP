<?php

namespace app\api\controller;

use Throwable;
use app\common\controller\Frontend;
use app\admin\model\Announcement as AnnouncementModel;

use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("公告管理")]
class Announcement extends Frontend
{
    protected array $noNeedLogin = ['index', 'detail', 'popup', 'scroll'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("获取公告列表"),
        Apidoc\Tag("公告管理"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Announcement/index"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1", mock: "@integer(1, 100)"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10", mock: "@integer(1, 100)"),
        Apidoc\Query(name: "type", type: "string", require: false, desc: "公告类型：normal=平台公告,important=平台动态", default: "", mock: "@pick(['normal', 'important'])"),
        Apidoc\Query(name: "title", type: "string", require: false, desc: "公告标题关键词", default: "", mock: "@ctitle(2, 10)"),
        Apidoc\Returned("list", type: "array", desc: "公告列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "公告ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "公告标题"),
        Apidoc\Returned("list[].content", type: "string", desc: "公告内容"),
        Apidoc\Returned("list[].type", type: "string", desc: "公告类型"),
        Apidoc\Returned("list[].type_text", type: "string", desc: "公告类型文本"),
        Apidoc\Returned("list[].status", type: "string", desc: "状态"),
        Apidoc\Returned("list[].status_text", type: "string", desc: "状态文本"),
        Apidoc\Returned("list[].is_popup", type: "int", desc: "是否弹出"),
        Apidoc\Returned("list[].popup_delay", type: "int", desc: "弹出延迟"),
        Apidoc\Returned("list[].sort", type: "int", desc: "排序"),
        Apidoc\Returned("list[].start_time", type: "string", desc: "开始时间"),
        Apidoc\Returned("list[].end_time", type: "string", desc: "结束时间"),
        Apidoc\Returned("list[].view_count", type: "int", desc: "查看次数"),
        Apidoc\Returned("list[].createtime", type: "string", desc: "创建时间"),
        Apidoc\Returned("list[].updatetime", type: "string", desc: "更新时间"),
        Apidoc\Returned("total", type: "int", desc: "总数量"),
        Apidoc\Returned("current_page", type: "int", desc: "当前页"),
        Apidoc\Returned("last_page", type: "int", desc: "最后一页"),
    ]
    /**
     * 获取公告列表
     * @throws Throwable
     */
    public function index(): void
    {
        $model = new AnnouncementModel();

        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);
        $type = $this->request->get('type', '');
        $title = $this->request->get('title', '');

        $where = [];

        if ($type) {
            $where[] = ['type', '=', $type];
        }

        if ($title) {
            $where[] = ['title', 'like', '%' . $title . '%'];
        }

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
        Apidoc\Title("获取公告详情"),
        Apidoc\Tag("公告管理"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Announcement/detail"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "公告ID", mock: "@integer(1, 100)"),
        Apidoc\Returned("announcement", type: "object", desc: "公告详情"),
        Apidoc\Returned("announcement.id", type: "int", desc: "公告ID"),
        Apidoc\Returned("announcement.title", type: "string", desc: "公告标题"),
        Apidoc\Returned("announcement.content", type: "string", desc: "公告内容"),
        Apidoc\Returned("announcement.type", type: "string", desc: "公告类型"),
        Apidoc\Returned("announcement.type_text", type: "string", desc: "公告类型文本"),
        Apidoc\Returned("announcement.status", type: "string", desc: "状态"),
        Apidoc\Returned("announcement.status_text", type: "string", desc: "状态文本"),
        Apidoc\Returned("announcement.is_popup", type: "int", desc: "是否弹出"),
        Apidoc\Returned("announcement.popup_delay", type: "int", desc: "弹出延迟"),
        Apidoc\Returned("announcement.sort", type: "int", desc: "排序"),
        Apidoc\Returned("announcement.start_time", type: "string", desc: "开始时间"),
        Apidoc\Returned("announcement.end_time", type: "string", desc: "结束时间"),
        Apidoc\Returned("announcement.view_count", type: "int", desc: "查看次数"),
        Apidoc\Returned("announcement.createtime", type: "string", desc: "创建时间"),
        Apidoc\Returned("announcement.updatetime", type: "string", desc: "更新时间"),
    ]
    /**
     * 获取公告详情
     * @throws Throwable
     */
    public function detail(): void
    {
        $id = $this->request->get('id/d', 0);

        if (!$id) {
            $this->error('公告ID不能为空');
        }

        $model = new AnnouncementModel();
        $announcement = $model->find($id);

        if (!$announcement) {
            $this->error('公告不存在');
        }

        $this->success('', [
            'announcement' => $announcement,
        ]);
    }

    #[
        Apidoc\Title("创建公告"),
        Apidoc\Tag("公告管理"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Announcement/create"),
        Apidoc\Param(name: "title", type: "string", require: true, desc: "公告标题", mock: "@ctitle(5, 20)"),
        Apidoc\Param(name: "content", type: "string", require: true, desc: "公告内容", mock: "@cparagraph(1, 3)"),
        Apidoc\Param(name: "type", type: "string", require: true, desc: "公告类型：normal=平台公告,important=平台动态", mock: "@pick(['normal', 'important'])"),
        Apidoc\Param(name: "status", type: "string", require: true, desc: "状态：0=禁用,1=启用", mock: "@pick(['0', '1'])"),
        Apidoc\Param(name: "is_popup", type: "int", require: true, desc: "是否弹出：0=否,1=是", mock: "@integer(0, 1)"),
        Apidoc\Param(name: "popup_delay", type: "int", require: false, desc: "弹出延迟时间（毫秒）", mock: "@integer(1000, 10000)"),
        Apidoc\Param(name: "sort", type: "int", require: false, desc: "排序", mock: "@integer(0, 999)"),
        Apidoc\Param(name: "start_time", type: "string", require: false, desc: "开始时间", mock: "@datetime"),
        Apidoc\Param(name: "end_time", type: "string", require: false, desc: "结束时间", mock: "@datetime"),
        Apidoc\Returned("announcement", type: "object", desc: "创建的公告信息"),
        Apidoc\Returned("announcement.id", type: "int", desc: "公告ID"),
    ]
    /**
     * 创建公告
     * @throws Throwable
     */
    public function create(): void
    {
        $params = $this->request->post();

        if (empty($params['title'])) {
            $this->error('公告标题不能为空');
        }

        if (empty($params['content'])) {
            $this->error('公告内容不能为空');
        }

        $model = new AnnouncementModel();
        $result = $model->save($params);

        if (!$result) {
            $this->error('创建公告失败');
        }

        $this->success('创建公告成功', [
            'announcement' => $model,
        ]);
    }

    #[
        Apidoc\Title("更新公告"),
        Apidoc\Tag("公告管理"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Announcement/update"),
        Apidoc\Param(name: "id", type: "int", require: true, desc: "公告ID", mock: "@integer(1, 100)"),
        Apidoc\Param(name: "title", type: "string", require: false, desc: "公告标题", mock: "@ctitle(5, 20)"),
        Apidoc\Param(name: "content", type: "string", require: false, desc: "公告内容", mock: "@cparagraph(1, 3)"),
        Apidoc\Param(name: "type", type: "string", require: false, desc: "公告类型：normal=平台公告,important=平台动态", mock: "@pick(['normal', 'important'])"),
        Apidoc\Param(name: "status", type: "string", require: false, desc: "状态：0=禁用,1=启用", mock: "@pick(['0', '1'])"),
        Apidoc\Param(name: "is_popup", type: "int", require: false, desc: "是否弹出：0=否,1=是", mock: "@integer(0, 1)"),
        Apidoc\Param(name: "popup_delay", type: "int", require: false, desc: "弹出延迟时间（毫秒）", mock: "@integer(1000, 10000)"),
        Apidoc\Param(name: "sort", type: "int", require: false, desc: "排序", mock: "@integer(0, 999)"),
        Apidoc\Param(name: "start_time", type: "string", require: false, desc: "开始时间", mock: "@datetime"),
        Apidoc\Param(name: "end_time", type: "string", require: false, desc: "结束时间", mock: "@datetime"),
        Apidoc\Returned("announcement", type: "object", desc: "更新的公告信息"),
    ]
    /**
     * 更新公告
     * @throws Throwable
     */
    public function update(): void
    {
        $params = $this->request->post();
        $id = $params['id'] ?? 0;

        if (!$id) {
            $this->error('公告ID不能为空');
        }

        $model = new AnnouncementModel();
        $announcement = $model->find($id);

        if (!$announcement) {
            $this->error('公告不存在');
        }

        unset($params['id']); // 移除ID，避免更新ID字段
        $result = $announcement->save($params);

        if (!$result) {
            $this->error('更新公告失败');
        }

        $this->success('更新公告成功', [
            'announcement' => $announcement,
        ]);
    }

    #[
        Apidoc\Title("删除公告"),
        Apidoc\Tag("公告管理"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Announcement/delete"),
        Apidoc\Param(name: "id", type: "int", require: true, desc: "公告ID", mock: "@integer(1, 100)"),
        Apidoc\Returned("success", type: "boolean", desc: "是否删除成功"),
    ]
    /**
     * 删除公告
     * @throws Throwable
     */
    public function delete(): void
    {
        $id = $this->request->post('id/d', 0);

        if (!$id) {
            $this->error('公告ID不能为空');
        }

        $model = new AnnouncementModel();
        $announcement = $model->find($id);

        if (!$announcement) {
            $this->error('公告不存在');
        }

        $result = $announcement->delete();

        if (!$result) {
            $this->error('删除公告失败');
        }

        $this->success('删除公告成功');
    }

    #[
        Apidoc\Title("获取弹出公告"),
        Apidoc\Tag("公告管理"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Announcement/popup"),
        Apidoc\Returned("list", type: "array", desc: "弹出公告列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "公告ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "公告标题"),
        Apidoc\Returned("list[].content", type: "string", desc: "公告内容"),
        Apidoc\Returned("list[].type", type: "string", desc: "公告类型"),
        Apidoc\Returned("list[].popup_delay", type: "int", desc: "弹出延迟"),
    ]
    /**
     * 获取弹出公告
     * @throws Throwable
     */
    public function popup(): void
    {
        $model = new AnnouncementModel();

        $where = [
            ['status', '=', '1'],
            ['is_popup', '=', '1'],
        ];

        // 时间筛选：显示当前时间范围内有效的公告
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

        $list = $model
            ->where($where)
            ->order('sort desc, id desc')
            ->select();

        $this->success('', [
            'list' => $list,
        ]);
    }

    #[
        Apidoc\Title("获取滚动公告"),
        Apidoc\Tag("公告管理"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Announcement/scroll"),
        Apidoc\Returned("list", type: "array", desc: "滚动公告列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "公告ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "公告标题"),
        Apidoc\Returned("list[].type", type: "string", desc: "公告类型"),
    ]
    /**
     * 获取滚动公告
     * @throws Throwable
     */
    public function scroll(): void
    {
        $model = new AnnouncementModel();

        $where = [
            ['status', '=', '1'],
            ['type', '=', 'normal'], // 只获取平台公告用于滚动
        ];

        // 时间筛选：显示当前时间范围内有效的公告
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

        $list = $model
            ->where($where)
            ->order('sort desc, id desc')
            ->select();

        $this->success('', [
            'list' => $list,
        ]);
    }
}
