<?php

namespace app\admin\controller;

use app\admin\model\CollectionSession as CollectionSessionModel;
use app\common\controller\Backend;

class CollectionSession extends Backend
{
    /**
     * @var object
     * @phpstan-var CollectionSessionModel
     */
    protected object $model;

    protected array $withJoinTable = [];

    protected string|array $preExcludeFields = ['create_time', 'update_time'];

    protected string|array $quickSearchField = ['title'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new CollectionSessionModel();
    }
}
