<?php

namespace app\admin\model;

use think\Model;

/**
 * 幸运转盘抽奖记录模型
 * @property int    $id          记录ID
 * @property int    $user_id     用户ID
 * @property int    $prize_id    奖品ID
 * @property string $prize_name  奖品名称
 * @property string $prize_type  奖品类型
 * @property int    $prize_value 奖品数值
 * @property string $status      状态(0=已撤销, 1=待发放, 2=已发放)
 * @property int    $draw_time   抽奖时间
 * @property int    $send_time   发放时间
 */
class LuckyDrawRecord extends Model
{
    protected $name = 'lucky_draw_record';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
}

