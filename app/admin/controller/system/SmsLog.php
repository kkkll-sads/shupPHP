<?php

namespace app\admin\controller\system;

use Throwable;
use app\common\controller\Backend;
use think\facade\Db;

/**
 * 短信记录管理
 */
class SmsLog extends Backend
{
    protected string|array $preExcludeFields = [];
    protected string|array $quickSearchField = ['mobile', 'content'];
    
    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * 查看短信记录列表
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);
        $quickSearch = $this->request->get('quickSearch', '');
        $status = $this->request->get('status', '');
        $platform = $this->request->get('platform', '');

        $where = [];
        
        // 快速搜索（手机号或内容）
        if ($quickSearch) {
            $where[] = ['mobile|content', 'like', '%' . $quickSearch . '%'];
        }
        
        // 状态筛选
        if ($status !== '') {
            $where[] = ['status', '=', (int)$status];
        }
        
        // 平台筛选
        if ($platform) {
            $where[] = ['platform', '=', $platform];
        }

        $res = Db::name('sms_log')
            ->where($where)
            ->order('id', 'desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page,
            ]);

        $list = $res->items();
        
        // 格式化数据
        foreach ($list as &$item) {
            $item['create_time_text'] = $item['create_time'] ? date('Y-m-d H:i:s', $item['create_time']) : '';
            $item['send_time_text'] = $item['send_time'] ? date('Y-m-d H:i:s', $item['send_time']) : '';
            $item['status_text'] = $this->getStatusText($item['status']);
            $item['platform_text'] = $item['platform'] === 'smsbao' ? '短信宝' : ($item['platform'] === 'weiwebs' ? '麦讯通' : $item['platform']);
        }

        $this->success('', [
            'list' => $list,
            'total' => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 获取状态文本
     */
    protected function getStatusText(int $status): string
    {
        return match ($status) {
            0 => '待发送',
            1 => '发送成功',
            2 => '发送失败',
            default => '未知',
        };
    }

    /**
     * 删除记录
     * @throws Throwable
     */
    public function del(): void
    {
        $ids = $this->request->param('ids/a', []);
        
        if (empty($ids)) {
            $this->error('请选择要删除的记录');
        }

        $count = Db::name('sms_log')
            ->whereIn('id', $ids)
            ->delete();

        if ($count) {
            $this->success('删除成功');
        } else {
            $this->error('删除失败');
        }
    }

    /**
     * 清空所有记录
     * @throws Throwable
     */
    public function clear(): void
    {
        $count = Db::name('sms_log')->count();
        
        if ($count == 0) {
            $this->error('没有可清空的记录');
        }

        Db::name('sms_log')->where('1=1')->delete();
        
        $this->success("成功清空 {$count} 条短信记录");
    }

    /**
     * 获取统计信息
     */
    public function stats(): void
    {
        $total = Db::name('sms_log')->count();
        $success = Db::name('sms_log')->where('status', 1)->count();
        $failed = Db::name('sms_log')->where('status', 2)->count();
        $pending = Db::name('sms_log')->where('status', 0)->count();
        
        $todayStart = strtotime(date('Y-m-d'));
        $todayTotal = Db::name('sms_log')->where('create_time', '>=', $todayStart)->count();
        $todaySuccess = Db::name('sms_log')->where('create_time', '>=', $todayStart)->where('status', 1)->count();

        $this->success('', [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'pending' => $pending,
            'today_total' => $todayTotal,
            'today_success' => $todaySuccess,
        ]);
    }
}
