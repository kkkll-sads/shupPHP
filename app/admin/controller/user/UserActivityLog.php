<?php

namespace app\admin\controller\user;

use Throwable;
use think\facade\Db;
use app\admin\model\UserActivityLog as UserActivityLogModel;
use app\common\controller\Backend;
use think\exception\HttpResponseException;

class UserActivityLog extends Backend
{
    /**
     * @var UserActivityLogModel
     */
    protected object $model;

    protected array $withJoinTable = ['user', 'relatedUser'];

    protected string|array $quickSearchField = ['user.username', 'user.nickname', 'remark', 'action_type', 'change_field'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new UserActivityLogModel();
    }

    /**
     * 清除全部日志
     * @throws Throwable
     */
    public function clearAll(): void
    {
        try {
            $count = $this->model->count();
            if ($count === 0) {
                $this->success('没有可清除的日志');
            }

            Db::startTrans();
            try {
                // 使用 Db::name 直接删除所有记录（表前缀会自动添加）
                $deleted = Db::name('user_activity_log')->delete(true);
                Db::commit();

                if ($deleted !== false) {
                    // 注意：success 会抛出 HttpResponseException，这里不要被后续 Throwable 捕获
                    $this->success("成功清除 {$count} 条日志记录");
                } else {
                    Db::rollback();
                    $this->error('清除失败');
                }
            } catch (HttpResponseException $e) {
                // HttpResponseException 是 success/error 方法抛出的正常响应异常
                // 此时事务已经 commit，不需要 rollback，直接重新抛出
                throw $e;
            } catch (Throwable $e) {
                Db::rollback();
                throw $e;
            }
        } catch (HttpResponseException $e) {
            // 正常响应，直接重新抛出
            throw $e;
        } catch (Throwable $e) {
            $this->error('清除失败：' . $e->getMessage());
        }
    }
}
