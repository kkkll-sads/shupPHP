<?php

namespace app\api\controller;

use app\common\library\LuckyDraw as LuckyDrawLibrary;
use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;

/**
 * 幸运转盘API控制器
 */
#[Apidoc\Title("幸运转盘")]
class LuckyDraw extends Frontend
{
    protected array $noNeedLogin = ['prizes', 'customerService', 'promotionalVideo'];
    protected array $noNeedPermission = ['prizes', 'customerService', 'promotionalVideo'];

    #[
        Apidoc\Title("获取奖品列表"),
        Apidoc\Tag("幸运转盘,抽奖"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/LuckyDraw/prizes"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data", type: "array", desc: "奖品列表"),
        Apidoc\Returned("data[].id", type: "int", desc: "奖品ID"),
        Apidoc\Returned("data[].name", type: "string", desc: "奖品名称"),
        Apidoc\Returned("data[].description", type: "string", desc: "奖品简介"),
        Apidoc\Returned("data[].thumbnail", type: "string", desc: "奖品缩略图"),
        Apidoc\Returned("data[].prize_type", type: "string", desc: "奖品类型(score=积分,money=金额,coupon=优惠券,item=实物)"),
        Apidoc\Returned("data[].prize_value", type: "int", desc: "奖品数值"),
        Apidoc\Returned("data[].sort", type: "int", desc: "排序值"),
    ]
    /**
     * 获取转盘奖品列表
     */
    public function prizes()
    {
        try {
            $prizes = LuckyDrawLibrary::getEnabledPrizes();
            
            // 清理敏感信息
            foreach ($prizes as &$prize) {
                unset($prize['daily_count']);
                unset($prize['total_count']);
                unset($prize['daily_limit']);
                unset($prize['total_limit']);
                unset($prize['probability']);
                unset($prize['status']);
            }

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $prizes
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    #[
        Apidoc\Title("获取用户抽奖信息"),
        Apidoc\Tag("幸运转盘,抽奖"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/LuckyDraw/info"),
        Apidoc\Query(name: "user_id", type: "int", require: false, desc: "用户ID(默认当前登录用户)", example: "1"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data", type: "object", desc: "抽奖统计信息"),
        Apidoc\Returned("data.current_draw_count", type: "int", desc: "当前剩余抽奖次数"),
        Apidoc\Returned("data.current_score", type: "int", desc: "兼容字段, 同 current_draw_count"),
        Apidoc\Returned("data.daily_limit", type: "int", desc: "每日抽奖次数上限(0表示不限)"),
        Apidoc\Returned("data.used_today", type: "int", desc: "今日已抽次数"),
        Apidoc\Returned("data.remaining_count", type: "int", desc: "今日可用抽奖次数"),
        Apidoc\Returned("data.score_cost", type: "int", desc: "兼容字段, 固定为0"),
        Apidoc\Returned("data.total_draw_count", type: "int", desc: "累计抽奖次数"),
        Apidoc\Returned("data.total_win_count", type: "int", desc: "累计中奖次数"),
    ]
    /**
     * 获取用户抽奖信息
     */
    public function info()
    {
        try {
            $userId = (int)$this->request->post('user_id', $this->auth->id);
            
            $stats = LuckyDrawLibrary::getUserStats($userId);
            
            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    #[
        Apidoc\Title("执行抽奖"),
        Apidoc\Tag("幸运转盘,抽奖"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/LuckyDraw/draw"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码(0=成功,其他=失败)"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("can_draw", type: "bool", desc: "是否可抽奖(失败时返回)"),
        Apidoc\Returned("current_draw_count", type: "int", desc: "抽奖后剩余抽奖次数"),
        Apidoc\Returned("current_score", type: "int", desc: "兼容字段, 同 current_draw_count"),
        Apidoc\Returned("remaining_count", type: "int", desc: "今日可用抽奖次数"),
        Apidoc\Returned("daily_limit", type: "int", desc: "每日抽奖次数上限(0表示不限)"),
        Apidoc\Returned("used_today", type: "int", desc: "今日已抽次数"),
        Apidoc\Returned("data", type: "object", desc: "中奖信息(成功时返回)"),
        Apidoc\Returned("data.prize_id", type: "int", desc: "奖品ID"),
        Apidoc\Returned("data.prize_name", type: "string", desc: "奖品名称"),
        Apidoc\Returned("data.prize_type", type: "string", desc: "奖品类型"),
        Apidoc\Returned("data.prize_value", type: "int", desc: "奖品数值"),
        Apidoc\Returned("data.description", type: "string", desc: "奖品描述"),
        Apidoc\Returned("data.thumbnail", type: "string", desc: "奖品缩略图"),
        Apidoc\Returned("data.record_id", type: "int", desc: "抽奖记录ID"),
    ]
    /**
     * 执行抽奖
     */
    public function draw()
    {
        try {
            $userId = $this->auth->id;
            
            $result = LuckyDrawLibrary::draw($userId);
            
            return json($result);
        } catch (\Exception $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    #[
        Apidoc\Title("获取抽奖记录"),
        Apidoc\Tag("幸运转盘,抽奖"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/LuckyDraw/records"),
        Apidoc\Query(name: "user_id", type: "int", require: false, desc: "用户ID(默认当前登录用户)", example: "1"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", example: "1"),
        Apidoc\Query(name: "page_size", type: "int", require: false, desc: "每页数量", example: "10"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data", type: "object", desc: "分页数据"),
        Apidoc\Returned("data.total", type: "int", desc: "总记录数"),
        Apidoc\Returned("data.page", type: "int", desc: "当前页码"),
        Apidoc\Returned("data.page_size", type: "int", desc: "每页数量"),
        Apidoc\Returned("data.records", type: "array", desc: "抽奖记录列表"),
        Apidoc\Returned("data.records[].id", type: "int", desc: "记录ID"),
        Apidoc\Returned("data.records[].prize_name", type: "string", desc: "奖品名称"),
        Apidoc\Returned("data.records[].prize_type", type: "string", desc: "奖品类型"),
        Apidoc\Returned("data.records[].prize_value", type: "int", desc: "奖品数值"),
        Apidoc\Returned("data.records[].status", type: "string", desc: "发放状态"),
        Apidoc\Returned("data.records[].draw_time", type: "int", desc: "抽奖时间"),
    ]
    /**
     * 获取抽奖记录
     */
    public function records()
    {
        try {
            $userId = (int)$this->request->post('user_id', $this->auth->id);
            $page = (int)$this->request->post('page', 1);
            $pageSize = (int)$this->request->post('page_size', 10);
            
            $records = LuckyDrawLibrary::getUserRecords($userId, $page, $pageSize);
            
            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $records
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    #[
        Apidoc\Title("检查抽奖资格"),
        Apidoc\Tag("幸运转盘,抽奖"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/LuckyDraw/check"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("can_draw", type: "bool", desc: "是否可以抽奖"),
        Apidoc\Returned("current_draw_count", type: "int", desc: "当前剩余抽奖次数"),
        Apidoc\Returned("current_score", type: "int", desc: "兼容字段, 同 current_draw_count"),
        Apidoc\Returned("remaining_count", type: "int", desc: "今日可用抽奖次数"),
        Apidoc\Returned("daily_limit", type: "int", desc: "每日抽奖次数上限(0表示不限)"),
        Apidoc\Returned("used_today", type: "int", desc: "今日已抽次数"),
        Apidoc\Returned("score_cost", type: "int", desc: "兼容字段, 固定为0"),
    ]
    /**
     * 检查是否可以抽奖
     */
    public function check()
    {
        try {
            $userId = $this->auth->id;
            
            $result = LuckyDrawLibrary::checkCanDraw($userId);
            
            return json($result);
        } catch (\Exception $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }
    #[
        Apidoc\Title("获取客服链接"),
        Apidoc\Tag("幸运转盘,配置"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/LuckyDraw/customerService"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data", type: "object", desc: "客服信息"),
        Apidoc\Returned("data.customer_service_url", type: "string", desc: "客服跳转链接URL"),
    ]
    /**
     * 获取客服链接（公开接口，无需登录）
     */
    public function customerService()
    {
        try {
            $customerServiceUrl = LuckyDrawLibrary::getConfig('customer_service_url', '');
            
            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'customer_service_url' => $customerServiceUrl
                ]
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    #[
        Apidoc\Title("获取宣传视频"),
        Apidoc\Tag("幸运转盘,配置"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/LuckyDraw/promotionalVideo"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data", type: "object", desc: "宣传视频信息"),
        Apidoc\Returned("data.promotional_video_title", type: "string", desc: "宣传视频标题"),
        Apidoc\Returned("data.promotional_video_summary", type: "string", desc: "宣传视频摘要"),
        Apidoc\Returned("data.promotional_video", type: "string", desc: "抽奖页面宣传视频URL"),
    ]
    /**
     * 获取宣传视频（公开接口，无需登录）
     */
    public function promotionalVideo()
    {
        try {
            $promotionalVideoTitle = LuckyDrawLibrary::getConfig('promotional_video_title', '');
            $promotionalVideoSummary = LuckyDrawLibrary::getConfig('promotional_video_summary', '');
            $promotionalVideo = LuckyDrawLibrary::getConfig('promotional_video', '');
            
            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'promotional_video_title' => $promotionalVideoTitle,
                    'promotional_video_summary' => $promotionalVideoSummary,
                    'promotional_video' => $promotionalVideo
                ]
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }
}

