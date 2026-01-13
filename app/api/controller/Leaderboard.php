<?php

namespace app\api\controller;

use app\common\controller\Frontend;
use app\common\model\User;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("排行榜")]
class Leaderboard extends Frontend
{
    protected array $noNeedLogin = ['score'];

    #[
        Apidoc\Title("积分排行榜"),
        Apidoc\Tag("排行榜,积分"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Leaderboard/score"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "返回数量(1-100)", default: "20"),
        Apidoc\Returned("list", type: "array", desc: "积分排行列表"),
        Apidoc\Returned("list[].rank", type: "int", desc: "名次"),
        Apidoc\Returned("list[].user_id", type: "int", desc: "用户ID"),
        Apidoc\Returned("list[].nickname", type: "string", desc: "昵称"),
        Apidoc\Returned("list[].avatar", type: "string", desc: "头像地址"),
        Apidoc\Returned("list[].score", type: "int", desc: "积分"),
    ]
    public function score(): void
    {
        $limit = (int)$this->request->get('limit/d', 20);
        $limit = max(1, min(100, $limit));

        $users = User::where('status', 'enable')
            ->field(['id', 'nickname', 'username', 'avatar', 'score'])
            ->order('score desc,id asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $list = [];
        foreach ($users as $index => $user) {
            $list[] = [
                'rank'     => $index + 1,
                'user_id'  => (int)$user['id'],
                'nickname' => $this->getDisplayName($user),
                'avatar'   => $user['avatar'] ?? '',
                'score'    => (int)$user['score'],
            ];
        }

        $this->success('', ['list' => $list]);
    }

    protected function getDisplayName(array $user): string
    {
        return $user['nickname'] ?: ($user['username'] ?? ('User' . $user['id']));
    }
}

