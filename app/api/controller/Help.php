<?php

namespace app\api\controller;

use Throwable;
use think\facade\Db;
use app\common\controller\Frontend;
use think\exception\HttpResponseException;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("帮助中心")]
class Help extends Frontend
{
    // 帮助中心接口不需要登录
    protected array $noNeedLogin = ['categories', 'questions'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("获取问题分类列表"),
        Apidoc\Tag("帮助中心"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Help/categories"),
        Apidoc\Returned("list", type: "array", desc: "分类列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "分类ID"),
        Apidoc\Returned("list[].name", type: "string", desc: "分类名称"),
        Apidoc\Returned("list[].code", type: "string", desc: "分类编码(account/trade/asset/other)"),
    ]
    /**
     * 获取问题分类列表
     * @throws Throwable
     */
    public function categories(): void
    {
        try {
            $list = Db::name('help_category')
                ->where('status', 1)
                ->order('sort desc,id asc')
                ->field('id,name,code')
                ->select()
                ->toArray();

            $this->success('获取成功', [
                'list' => $list,
            ]);
        } catch (HttpResponseException $e) {
            // 保持原有响应（如 success / error）
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("获取某分类下的问题列表"),
        Apidoc\Tag("帮助中心"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Help/questions"),
        Apidoc\Query(name: "category_id", type: "int", require: false, desc: "分类ID", mock: "@integer(1, 20)"),
        Apidoc\Query(name: "category_code", type: "string", require: false, desc: "分类编码(account/trade/asset/other)", mock: "@pick(['account','trade','asset','other'])"),
        Apidoc\Returned("list", type: "array", desc: "问题列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "问题ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "问题标题"),
        Apidoc\Returned("list[].content", type: "string", desc: "问题内容"),
        Apidoc\Returned("list[].category_id", type: "int", desc: "分类ID"),
    ]
    /**
     * 获取问题列表（按分类）
     * @throws Throwable
     */
    public function questions(): void
    {
        $categoryId   = $this->request->get('category_id/d', 0);
        $categoryCode = $this->request->get('category_code/s', '');

        if (!$categoryId && $categoryCode) {
            $categoryId = (int)Db::name('help_category')
                ->where('code', $categoryCode)
                ->value('id');
        }

        if (!$categoryId) {
            $this->error('分类不存在');
        }

        try {
            $list = Db::name('help_question')
                ->where([
                    ['category_id', '=', $categoryId],
                    ['status', '=', 1],
                ])
                ->order('sort desc,id asc')
                ->field('id,title,content,category_id')
                ->select()
                ->toArray();

            $this->success('获取成功', [
                'list' => $list,
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }
}


