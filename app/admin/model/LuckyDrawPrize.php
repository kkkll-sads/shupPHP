<?php

namespace app\admin\model;

use think\Model;

/**
 * 幸运转盘奖品模型
 * @property int    $id              奖品ID
 * @property string $name            奖品名称
 * @property string $description     奖品简介
 * @property string $thumbnail       奖品缩略图
 * @property string $prize_type      奖品类型(score=积分, money=金额, coupon=优惠券, item=实物)
 * @property int    $prize_value     奖品数值
 * @property int    $probability     中奖概率(万分比)
 * @property int    $daily_limit     每日限制数量
 * @property int    $daily_count     当日已抽取数量
 * @property int    $total_limit     总限制数量
 * @property int    $total_count     总已抽取数量
 * @property int    $sort            排序
 * @property string $status          状态
 */
class LuckyDrawPrize extends Model
{
    protected $name = 'lucky_draw_prize';
    protected $autoWriteTimestamp = true;

    public function getThumbnailAttr($value): string
    {
        return full_url($value, false, '');
    }

    public function setThumbnailAttr($value): string
    {
        return $value == full_url('', false, '') ? '' : $value;
    }
}

