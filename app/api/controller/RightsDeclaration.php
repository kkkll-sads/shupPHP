<?php

namespace app\api\controller;

use think\facade\Db;
use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;
use think\exception\HttpResponseException;

#[Apidoc\Title("确权申报管理")]
class RightsDeclaration extends Frontend
{
    protected array $noNeedLogin = [];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("提交确权申报"),
        Apidoc\Tag("确权申报,提交申报"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/rightsDeclaration/submit"),
        Apidoc\Param(name: "voucher_type", type: "string", require: true, desc: "凭证类型：screenshot-截图, transfer_record-转账记录, other-其他凭证"),
        Apidoc\Param(name: "amount", type: "float", require: true, desc: "申请金额（0.01-100000）"),
        Apidoc\Param(name: "images", type: "string", require: true, desc: "图片链接数组的JSON字符串，至少一张图片"),
        Apidoc\Param(name: "remark", type: "string", require: false, desc: "用户备注"),
        Apidoc\Returned("declaration_id", type: "int", desc: "申报ID"),
    ]
    public function submit(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;

        // 检查用户是否为交易用户
        $user = Db::name('user')->find($userId);
        if (!$user) {
            $this->error('用户不存在', [], 401);
        }

        if (($user['user_type'] ?? 0) != 2) {
            $this->error('只有交易用户才能提交确权申报');
        }

        // 优先从原始JSON输入获取数据
        $inputData = null;
        $contentType = $this->request->header('Content-Type', '');
        if (strpos($contentType, 'application/json') !== false) {
            $rawInput = file_get_contents('php://input');
            $inputData = json_decode($rawInput, true);
        }

        // 获取参数（优先从JSON输入，其次从POST）
        $voucherType = $inputData['voucher_type'] ?? $this->request->post('voucher_type');
        $amount = (float)($inputData['amount'] ?? $this->request->post('amount', 0));
        $images = $inputData['images'] ?? $this->request->post('images', ''); // JSON字符串或数组
        $remark = $inputData['remark'] ?? $this->request->post('remark', '');


        // 参数验证
        if (!$voucherType || !in_array($voucherType, ['screenshot', 'transfer_record', 'other'])) {
            $this->error('请选择有效的凭证类型');
        }

        if ($amount <= 0) {
            $this->error('请输入有效的申请金额');
        }

        if ($amount > 100000) {
            $this->error('单次申报金额不能超过10万元');
        }

        // 处理图片参数
        $imageUrls = [];

        // 如果是字符串，尝试JSON解析
        if (is_string($images)) {
            $decoded = json_decode($images, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $imageUrls = $decoded;
            }
        }
        // 如果已经是数组，直接使用
        elseif (is_array($images)) {
            $imageUrls = $images;
        }

        if (empty($imageUrls)) {
            $this->error('请上传至少一张凭证图片');
        }

        if (count($imageUrls) > 10) {
            $this->error('最多只能上传10张图片');
        }

        // 验证图片URL格式
        foreach ($imageUrls as $url) {
            // 允许完整的URL、blob URL和存储路径
            $isValidUrl = filter_var($url, FILTER_VALIDATE_URL);
            $isBlobUrl = preg_match('/^blob:/', $url);
            $isStoragePath = preg_match('/^\/storage\//', $url);

            if (!$isValidUrl && !$isBlobUrl && !$isStoragePath) {
                $this->error('图片链接格式不正确');
            }
        }

        // 检查用户是否已有待审核的申报
        $existingPending = Db::name('rights_declaration')
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->count();

        if ($existingPending > 0) {
            $this->error('您还有待审核的申报，请等待审核完成后再提交');
        }

        Db::startTrans();
        try {

            // 创建申报记录
            $declarationId = Db::name('rights_declaration')->insertGetId([
                'user_id' => $userId,
                'voucher_type' => $voucherType,
                'amount' => $amount,
                'images' => json_encode($imageUrls, JSON_UNESCAPED_UNICODE),
                'remark' => $remark,
                'status' => 'pending',
                'create_time' => time(),
            ]);

            // 记录用户活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'rights_declaration_submit',
                'change_field' => 'rights_declaration',
                'change_value' => $amount,
                'before_value' => 0,
                'after_value' => $amount,
                'remark' => '提交确权申报：金额 ' . $amount . ' 元',
                'extra' => json_encode([
                    'declaration_id' => $declarationId,
                    'voucher_type' => $voucherType,
                    'amount' => $amount,
                    'images_count' => count($imageUrls),
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => time(),
                'update_time' => time(),
            ]);

            Db::commit();

            $this->success('确权申报提交成功，请等待管理员审核', [
                'declaration_id' => $declarationId,
            ]);

        } catch (\Exception $e) {
            Db::rollback();
            // 如果是HttpResponseException（成功响应或错误响应），直接重新抛出
            if ($e instanceof HttpResponseException) {
                throw $e;
            }
            // 其他异常才包装为错误响应
            $this->error('提交失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("获取申报记录列表"),
        Apidoc\Tag("确权申报,申报记录"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/rightsDeclaration/list"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大100)", default: "20"),
        Apidoc\Query(name: "status", type: "string", require: false, desc: "状态筛选：pending-待审核, approved-已通过, rejected-已拒绝, cancelled-已撤销"),
        Apidoc\Returned("list", type: "array", desc: "申报记录列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "申报ID"),
        Apidoc\Returned("list[].voucher_type", type: "string", desc: "凭证类型"),
        Apidoc\Returned("list[].voucher_type_text", type: "string", desc: "凭证类型文本"),
        Apidoc\Returned("list[].amount", type: "float", desc: "申请金额"),
        Apidoc\Returned("list[].status", type: "string", desc: "审核状态：pending-待审核, approved-已通过, rejected-已拒绝, cancelled-已撤销"),
        Apidoc\Returned("list[].status_text", type: "string", desc: "审核状态文本"),
        Apidoc\Returned("list[].images_array", type: "array", desc: "图片链接数组"),
        Apidoc\Returned("list[].create_time_text", type: "string", desc: "提交时间"),
        Apidoc\Returned("list[].review_time_text", type: "string", desc: "审核时间"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
    ]
    public function list(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 20);
        $status = $this->request->param('status', '');

        $limit = min($limit, 100); // 最大100条

        $where = ['user_id' => $userId];
        if ($status && in_array($status, ['pending', 'approved', 'rejected', 'cancelled'])) {
            $where['status'] = $status;
        }

        $list = Db::name('rights_declaration')
            ->where($where)
            ->field([
                'id',
                'voucher_type',
                'amount',
                'images',
                'status',
                'review_remark',
                'review_time',
                'create_time',
                'update_time',
            ])
            ->order('create_time desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 处理数据
        foreach ($list as &$item) {
            // 凭证类型文本
            $voucherTypeMap = [
                'screenshot' => '截图',
                'transfer_record' => '转账记录',
                'other' => '其他凭证',
            ];
            $item['voucher_type_text'] = $voucherTypeMap[$item['voucher_type']] ?? '未知';

            // 状态文本
            $statusMap = [
                'pending' => '待审核',
                'approved' => '已通过',
                'rejected' => '已拒绝',
                'cancelled' => '已撤销',
            ];
            $item['status_text'] = $statusMap[$item['status']] ?? '未知';

            // 处理图片
            $item['images_array'] = $item['images'] ? json_decode($item['images'], true) : [];

            // 时间格式化
            $item['create_time_text'] = date('Y-m-d H:i:s', $item['create_time']);
            $item['review_time_text'] = $item['review_time'] ? date('Y-m-d H:i:s', $item['review_time']) : '';
        }

        $total = Db::name('rights_declaration')->where($where)->count();

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[
        Apidoc\Title("获取申报详情"),
        Apidoc\Tag("确权申报,申报详情"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/rightsDeclaration/detail"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "申报ID"),
        Apidoc\Returned("detail", type: "object", desc: "申报详情"),
        Apidoc\Returned("detail.id", type: "int", desc: "申报ID"),
        Apidoc\Returned("detail.voucher_type", type: "string", desc: "凭证类型"),
        Apidoc\Returned("detail.voucher_type_text", type: "string", desc: "凭证类型文本"),
        Apidoc\Returned("detail.amount", type: "float", desc: "申请金额"),
        Apidoc\Returned("detail.status", type: "string", desc: "审核状态：pending-待审核, approved-已通过, rejected-已拒绝, cancelled-已撤销"),
        Apidoc\Returned("detail.status_text", type: "string", desc: "审核状态文本"),
        Apidoc\Returned("detail.images_array", type: "array", desc: "图片链接数组"),
        Apidoc\Returned("detail.remark", type: "string", desc: "用户备注"),
        Apidoc\Returned("detail.review_remark", type: "string", desc: "审核备注"),
        Apidoc\Returned("detail.create_time_text", type: "string", desc: "提交时间"),
        Apidoc\Returned("detail.review_time_text", type: "string", desc: "审核时间"),
    ]
    public function detail(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;
        $id = $this->request->param('id/d', 0);

        if (!$id) {
            $this->error('参数错误');
        }

        $detail = Db::name('rights_declaration')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->find();

        if (!$detail) {
            $this->error('申报记录不存在');
        }

        // 处理数据
        $voucherTypeMap = [
            'screenshot' => '截图',
            'transfer_record' => '转账记录',
            'other' => '其他凭证',
        ];
        $detail['voucher_type_text'] = $voucherTypeMap[$detail['voucher_type']] ?? '未知';

        $statusMap = [
            'pending' => '待审核',
            'approved' => '已通过',
            'rejected' => '已拒绝',
            'cancelled' => '已撤销',
        ];
        $detail['status_text'] = $statusMap[$detail['status']] ?? '未知';

        $detail['images_array'] = $detail['images'] ? json_decode($detail['images'], true) : [];
        $detail['create_time_text'] = date('Y-m-d H:i:s', $detail['create_time']);
        $detail['review_time_text'] = $detail['review_time'] ? date('Y-m-d H:i:s', $detail['review_time']) : '';

        $this->success('', [
            'detail' => $detail,
        ]);
    }

    #[
        Apidoc\Title("确权审核状态查询"),
        Apidoc\Tag("确权申报,审核状态"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/rightsDeclaration/reviewStatus"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "20"),
        Apidoc\Query(name: "status", type: "string", require: false, desc: "状态筛选：pending-待审核, approved-已通过, rejected-已拒绝, cancelled-已撤销"),
        Apidoc\Returned("list", type: "array", desc: "申报记录列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "申报ID"),
        Apidoc\Returned("list[].voucher_type", type: "string", desc: "凭证类型"),
        Apidoc\Returned("list[].voucher_type_text", type: "string", desc: "凭证类型文本"),
        Apidoc\Returned("list[].amount", type: "float", desc: "申请金额"),
        Apidoc\Returned("list[].status", type: "string", desc: "审核状态"),
        Apidoc\Returned("list[].status_text", type: "string", desc: "审核状态文本"),
        Apidoc\Returned("list[].images_array", type: "array", desc: "图片链接数组"),
        Apidoc\Returned("list[].review_remark", type: "string", desc: "审核备注"),
        Apidoc\Returned("list[].create_time_text", type: "string", desc: "提交时间"),
        Apidoc\Returned("list[].review_time_text", type: "string", desc: "审核时间"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
        Apidoc\Returned("pending_count", type: "int", desc: "待审核数量"),
        Apidoc\Returned("approved_count", type: "int", desc: "已通过数量"),
    ]
    public function reviewStatus(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 20);
        $status = $this->request->param('status', '');

        $limit = min($limit, 50); // 最大50条

        $where = ['user_id' => $userId];
        if ($status && in_array($status, ['pending', 'approved', 'rejected', 'cancelled'])) {
            $where['status'] = $status;
        }

        $list = Db::name('rights_declaration')
            ->where($where)
            ->field([
                'id',
                'voucher_type',
                'amount',
                'images',
                'status',
                'review_remark',
                'review_time',
                'create_time',
            ])
            ->order('create_time desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 处理数据
        foreach ($list as &$item) {
            // 凭证类型文本
            $voucherTypeMap = [
                'screenshot' => '截图',
                'transfer_record' => '转账记录',
                'other' => '其他凭证',
            ];
            $item['voucher_type_text'] = $voucherTypeMap[$item['voucher_type']] ?? '未知';

            // 状态文本
            $statusMap = [
                'pending' => '待审核',
                'approved' => '已通过',
                'rejected' => '已拒绝',
                'cancelled' => '已撤销',
            ];
            $item['status_text'] = $statusMap[$item['status']] ?? '未知';

            // 处理图片
            $item['images_array'] = $item['images'] ? json_decode($item['images'], true) : [];

            // 时间格式化
            $item['create_time_text'] = date('Y-m-d H:i:s', $item['create_time']);
            $item['review_time_text'] = $item['review_time'] ? date('Y-m-d H:i:s', $item['review_time']) : '';
        }

        $total = Db::name('rights_declaration')->where($where)->count();

        // 获取统计数据
        $pendingCount = Db::name('rights_declaration')
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->count();

        $approvedCount = Db::name('rights_declaration')
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->count();

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pending_count' => $pendingCount,
            'approved_count' => $approvedCount,
        ]);
    }

    #[
        Apidoc\Title("撤销确权申报"),
        Apidoc\Tag("确权申报,撤销申报"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/rightsDeclaration/cancel"),
        Apidoc\Param(name: "id", type: "int", require: true, desc: "申报ID"),
        Apidoc\Param(name: "reason", type: "string", require: false, desc: "撤销原因"),
    ]
    public function cancel(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;
        $id = $this->request->post('id/d', 0);
        $reason = $this->request->post('reason', '');

        if (!$id) {
            $this->error('参数错误：缺少申报ID');
        }

        Db::startTrans();
        try {
            // 查询申报记录
            $declaration = Db::name('rights_declaration')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->find();

            if (!$declaration) {
                Db::rollback();
                $this->error('申报记录不存在');
            }

            if ($declaration['status'] !== 'pending') {
                Db::rollback();
                $this->error('仅待审核状态的申报可以撤销');
            }

            // 更新申报状态为撤销（可以添加一个cancelled状态）
            Db::name('rights_declaration')->where('id', $id)->update([
                'status' => 'cancelled',
                'review_remark' => '用户主动撤销：' . ($reason ?: '无原因'),
                'review_admin_id' => 0, // 系统操作
                'review_time' => time(),
                'update_time' => time(),
            ]);

            // 记录用户活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'rights_declaration_cancelled',
                'change_field' => 'rights_declaration',
                'change_value' => (float)$declaration['amount'],
                'before_value' => (float)$declaration['amount'],
                'after_value' => 0,
                'remark' => '撤销确权申报：金额 ' . $declaration['amount'] . ' 元',
                'extra' => json_encode([
                    'declaration_id' => $id,
                    'voucher_type' => $declaration['voucher_type'],
                    'amount' => $declaration['amount'],
                    'cancel_reason' => $reason,
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => time(),
                'update_time' => time(),
            ]);

            Db::commit();
            $this->success('申报已撤销');

        } catch (\Exception $e) {
            Db::rollback();
            // 如果是HttpResponseException（成功响应或错误响应），直接重新抛出
            if ($e instanceof HttpResponseException) {
                throw $e;
            }
            // 其他异常才包装为错误响应
            $this->error('撤销失败：' . $e->getMessage());
        }
    }
}
