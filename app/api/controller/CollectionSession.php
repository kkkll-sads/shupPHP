<?php

namespace app\api\controller;

use think\facade\Db;
use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("藏品专场管理")]
class CollectionSession extends Frontend
{
    protected array $noNeedLogin = ['index', 'detail'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("藏品专场列表"),
        Apidoc\Tag("藏品商城,专场列表"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionSession/index"),
        Apidoc\Returned("list", type: "array", desc: "专场列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "专场ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "专场标题"),
        Apidoc\Returned("list[].image", type: "string", desc: "专场图片完整URL"),
        Apidoc\Returned("list[].start_time", type: "string", desc: "开始时间(HH:mm)"),
        Apidoc\Returned("list[].end_time", type: "string", desc: "结束时间(HH:mm)"),
        Apidoc\Returned("list[].roi", type: "string", desc: "年化收益率"),
        Apidoc\Returned("list[].quota", type: "string", desc: "额度"),
        Apidoc\Returned("list[].code", type: "string", desc: "资产池代码"),
        Apidoc\Returned("list[].sub_name", type: "string", desc: "副标题"),
    ]
    public function index(): void
    {
        $list = Db::name('collection_session')
            ->where('status', '1')
            ->field([
                'id',
                'title',
                'image',
                'start_time',
                'end_time',
                'roi',
                'quota',
                'code',
                'sub_name',
            ])
            ->order('sort desc, id desc')
            ->select()
            ->toArray();

        // 处理图片完整URL
        foreach ($list as &$item) {
            $item['image'] = $item['image'] ? full_url($item['image'], false) : '';
            
            // 判断专场是否正在进行中
            $currentTime = date('H:i');
            $item['is_active'] = $this->isTimeInRange($currentTime, $item['start_time'], $item['end_time']);
        }

        $this->success('', [
            'list' => $list,
        ]);
    }

    #[
        Apidoc\Title("藏品专场详情"),
        Apidoc\Tag("藏品商城,专场详情"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/collectionSession/detail"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "专场ID"),
        Apidoc\Returned("id", type: "int", desc: "专场ID"),
        Apidoc\Returned("title", type: "string", desc: "专场标题"),
        Apidoc\Returned("image", type: "string", desc: "专场图片完整URL"),
        Apidoc\Returned("start_time", type: "string", desc: "开始时间(HH:mm)"),
        Apidoc\Returned("end_time", type: "string", desc: "结束时间(HH:mm)"),
        Apidoc\Returned("roi", type: "string", desc: "年化收益率"),
        Apidoc\Returned("quota", type: "string", desc: "额度"),
        Apidoc\Returned("code", type: "string", desc: "资产池代码"),
        Apidoc\Returned("sub_name", type: "string", desc: "副标题"),
    ]
    public function detail(): void
    {
        $id = $this->request->param('id/d', 0);
        
        if (!$id) {
            $this->error('参数错误');
        }

        $detail = Db::name('collection_session')
            ->where('id', $id)
            ->where('status', '1')
            ->field([
                'id',
                'title',
                'image',
                'start_time',
                'end_time',
                'roi',
                'quota',
                'code',
                'sub_name',
            ])
            ->find();

        if (!$detail) {
            $this->error('专场不存在或已下架');
        }

        // 处理图片完整URL
        $detail['image'] = $detail['image'] ? full_url($detail['image'], false) : '';
        
        // 判断专场是否正在进行中
        $currentTime = date('H:i');
        $detail['is_active'] = $this->isTimeInRange($currentTime, $detail['start_time'], $detail['end_time']);

        $this->success('', $detail);
    }

    /**
     * 判断当前时间是否在时间范围内
     */
    private function isTimeInRange(string $currentTime, string $startTime, string $endTime): bool
    {
        // 如果结束时间小于开始时间，说明跨天
        if ($endTime < $startTime) {
            return $currentTime >= $startTime || $currentTime <= $endTime;
        }
        return $currentTime >= $startTime && $currentTime <= $endTime;
    }
}

