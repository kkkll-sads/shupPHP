<?php

namespace app\admin\controller\user;

use app\common\controller\Backend;

/**
 * 旧资产解锁记录
 */
class OldAssetsUnlock extends Backend
{
    /**
     * @var object
     * @phpstan-var \app\admin\model\user\OldAssetsUnlock
     */
    protected object $model;

    protected array $withJoinTable = ['user'];

    protected string|array $preExcludeFields = ['create_time'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new \app\admin\model\user\OldAssetsUnlock();
    }

    /**
     * 查看
     */
    public function index(): void
    {
        // 使用父类的标准实现
        parent::index();
    }
}
