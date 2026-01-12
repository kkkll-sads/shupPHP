<?php

namespace app\api\controller;

use app\common\controller\Frontend;
use think\facade\Db;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("用户问卷")]
class Questionnaire extends Frontend
{
    protected array $noNeedLogin = [];
    
    #[
        Apidoc\Title("提交问卷"),
        Apidoc\Tag("用户问卷"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Questionnaire/submit"),
        Apidoc\Param(name:"title", type:"string", require:true, desc:"问卷标题"),
        Apidoc\Param(name:"content", type:"string", require:true, desc:"问卷内容"),
        Apidoc\Param(name:"images", type:"string", require:false, desc:"图片地址（多张用逗号分隔）"),
        Apidoc\Returned("id", type:"int", desc:"问卷ID"),
    ]
    public function submit(): void
    {
        $userId = $this->auth->id;
        $title = $this->request->post('title', '');
        $content = $this->request->post('content', '');
        $images = $this->request->post('images', '');
        
        if (empty($title)) {
            $this->error('请填写问卷标题');
        }
        
        if (empty($content)) {
            $this->error('请填写问卷内容');
        }
        
        // 限制每天提交次数
        $todayStart = strtotime('today');
        $todayCount = Db::name('user_questionnaire')
            ->where('user_id', $userId)
            ->where('create_time', '>=', $todayStart)
            ->count();
        
        if ($todayCount >= 3) {
            $this->error('每天最多提交3份问卷');
        }
        
        $data = [
            'user_id' => $userId,
            'title' => $title,
            'content' => $content,
            'images' => $images,
            'status' => 0,
            'reward_power' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ];
        
        $id = Db::name('user_questionnaire')->insertGetId($data);
        
        if ($id) {
            $this->success('提交成功，请等待审核', ['id' => $id]);
        } else {
            $this->error('提交失败');
        }
    }
    
    #[
        Apidoc\Title("我的问卷列表"),
        Apidoc\Tag("用户问卷"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Questionnaire/myList"),
        Apidoc\Query(name:"page", type:"int", require:false, desc:"页码", default:1),
        Apidoc\Query(name:"limit", type:"int", require:false, desc:"每页数量", default:10),
        Apidoc\Returned("list", type:"array", desc:"问卷列表", children:[
            ["name"=>"id", "type"=>"int", "desc"=>"ID"],
            ["name"=>"title", "type"=>"string", "desc"=>"标题"],
            ["name"=>"content", "type"=>"string", "desc"=>"内容"],
            ["name"=>"images", "type"=>"string", "desc"=>"图片"],
            ["name"=>"status", "type"=>"int", "desc"=>"状态:0=待审核,1=已采纳,2=已拒绝"],
            ["name"=>"status_text", "type"=>"string", "desc"=>"状态文字"],
            ["name"=>"create_time", "type"=>"int", "desc"=>"创建时间戳"],
            ["name"=>"create_time_text", "type"=>"string", "desc"=>"创建时间"],
            ["name"=>"reward_power", "type"=>"int", "desc"=>"奖励算力"],
            ["name"=>"admin_remark", "type"=>"string", "desc"=>"管理员备注"],
        ]),
        Apidoc\Returned("total", type:"int", desc:"总记录数"),
    ]
    public function myList(): void
    {
        $userId = $this->auth->id;
        $page = $this->request->get('page', 1);
        $limit = $this->request->get('limit', 10);
        
        $list = Db::name('user_questionnaire')
            ->where('user_id', $userId)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        
        $total = Db::name('user_questionnaire')
            ->where('user_id', $userId)
            ->count();
        
        $statusMap = [0 => '待审核', 1 => '已采纳', 2 => '已拒绝'];
        foreach ($list as &$item) {
            $item['status_text'] = $statusMap[$item['status']] ?? '未知';
            $item['create_time_text'] = date('Y-m-d H:i:s', $item['create_time']);
        }
        
        $this->success('', [
            'list' => $list,
            'total' => $total,
        ]);
    }
}
