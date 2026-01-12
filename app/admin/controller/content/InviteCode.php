<?php

namespace app\admin\controller\content;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\InviteCode as InviteCodeModel;

class InviteCode extends Backend
{
    /**
     * @var object
     * @phpstan-var InviteCodeModel
     */
    protected object $model;

    protected array $withJoinTable = ['user'];

    // 排除字段
    protected string|array $preExcludeFields = [];

    protected string|array $quickSearchField = ['code', 'id', 'user.mobile'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new InviteCodeModel();
    }

    /**
     * 查看 - 只读查看邀请码及上下级关系
     * @throws Throwable
     */
    public function index(): void
    {
        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $res = $this->model
            ->withJoin($this->withJoinTable, $this->withJoinType)
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        // 获取每个邀请码的下级用户数和上级用户信息
        $items = $res->items();
        foreach ($items as &$item) {
            // 修正：使用inviter_id匹配下级用户，而不是invite_code
            // invite_code是用户自己的邀请码，inviter_id才是邀请人的用户ID
            $userId = $item['user_id'] ?? 0;
            $childUsers = \think\facade\Db::name('user')
                ->where('inviter_id', $userId)
                ->field('id, nickname, username, mobile, create_time')
                ->order('create_time desc')
                ->select();
            $item['child_count'] = count($childUsers);
            $item['child_users'] = $childUsers;
            
            // 使用次数应该等于下级用户数（从数据库读取的use_count字段已经是正确的）
            // 这里只是确保显示值与实际一致
            $item['use_count'] = count($childUsers);
            
            // 获取上级用户信息
            if ($item['user']) {
                $upuser = \think\facade\Db::name('user')
                    ->where('id', $item['user']['inviter_id'] ?? 0)
                    ->field('id, nickname, username, mobile')
                    ->find();
                $item['upuser'] = $upuser ?: null;
            }
        }

        $this->success('', [
            'list'   => $items,
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 编辑 - 获取完整的上下级关系树
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk  = $this->model->getPk();
        $id  = $this->request->param($pk);
        $row = $this->model->with(['user'])->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        $row = $row->toArray();

        // 获取下级用户信息
        $childUsers = \think\facade\Db::name('user')
            ->where('invite_code', $row['code'])
            ->field('id, nickname, username, mobile, inviter_id, invite_code')
            ->select();
        $row['child_users'] = $childUsers;
        $row['child_count'] = count($childUsers);

        // 获取上级用户信息
        if ($row['user']) {
            $upuser = \think\facade\Db::name('user')
                ->where('id', $row['user']['inviter_id'] ?? 0)
                ->field('id, nickname, username, mobile, inviter_id, invite_code')
                ->find();
            $row['upuser'] = $upuser ?: null;
            
            // 如果有上级用户，继续获取上级的上级
            if ($upuser) {
                $upupuser = \think\facade\Db::name('user')
                    ->where('id', $upuser['inviter_id'] ?? 0)
                    ->field('id, nickname, username, mobile')
                    ->find();
                $row['upupuser'] = $upupuser ?: null;
            }
        }

        $this->success('', [
            'row' => $row
        ]);
    }

}

