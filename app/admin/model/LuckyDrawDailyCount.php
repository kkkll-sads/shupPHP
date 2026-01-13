<?php

namespace app\admin\model;

use think\Model;

/**
 * 用户每日抽奖次数记录模型
 * @property int    $id        ID
 * @property int    $user_id   用户ID
 * @property string $draw_date 抽奖日期
 * @property int    $draw_count 该日已抽取次数
 * @property int    $reset_time 重置时间
 */
class LuckyDrawDailyCount extends Model
{
    protected $name = 'lucky_draw_daily_count';
    protected $autoWriteTimestamp = true;
}

