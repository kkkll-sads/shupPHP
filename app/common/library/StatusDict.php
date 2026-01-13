<?php

namespace app\common\library;

/**
 * 前后端统一状态映射字典
 * 
 * 用于统一前后端的状态码、枚举值、文本映射
 * 所有状态相关的映射都应在此定义，确保前后端一致
 */
class StatusDict
{
    // ============================================================
    // 藏品相关状态
    // ============================================================
    
    /**
     * 藏品商品上架状态
     * ba_collection_item.status
     */
    const ITEM_STATUS_OFFLINE = '0';  // 下架
    const ITEM_STATUS_ONLINE = '1';   // 上架
    
    public static function getItemStatusMap(): array
    {
        return [
            self::ITEM_STATUS_OFFLINE => '下架',
            self::ITEM_STATUS_ONLINE => '上架',
        ];
    }
    
    /**
     * 用户藏品寄售状态
     * ba_user_collection.consignment_status
     */
    const CONSIGNMENT_STATUS_NONE = 0;      // 未寄售
    const CONSIGNMENT_STATUS_SELLING = 1;   // 寄售中
    const CONSIGNMENT_STATUS_SOLD = 2;      // 已售出
    const CONSIGNMENT_STATUS_FAILED = 3;    // 寄售失败
    
    public static function getConsignmentStatusMap(): array
    {
        return [
            self::CONSIGNMENT_STATUS_NONE => '未寄售',
            self::CONSIGNMENT_STATUS_SELLING => '寄售中',
            self::CONSIGNMENT_STATUS_SOLD => '已售出',
            self::CONSIGNMENT_STATUS_FAILED => '寄售失败',
        ];
    }
    
    /**
     * 寄售记录状态
     * ba_collection_consignment.status
     */
    const CONSIGNMENT_RECORD_STATUS_CANCELLED = 0;  // 已取消
    const CONSIGNMENT_RECORD_STATUS_SELLING = 1;    // 寄售中
    const CONSIGNMENT_RECORD_STATUS_SOLD = 2;       // 已售出
    const CONSIGNMENT_RECORD_STATUS_OFF_SHELF = 3;  // 已下架/流拍
    
    public static function getConsignmentRecordStatusMap(): array
    {
        return [
            self::CONSIGNMENT_RECORD_STATUS_CANCELLED => '已取消',
            self::CONSIGNMENT_RECORD_STATUS_SELLING => '寄售中',
            self::CONSIGNMENT_RECORD_STATUS_SOLD => '已售出',
            self::CONSIGNMENT_RECORD_STATUS_OFF_SHELF => '流拍失败',
        ];
    }
    
    /**
     * 用户藏品矿机状态
     * ba_user_collection.mining_status
     */
    const MINING_STATUS_NORMAL = 0;  // 正常
    const MINING_STATUS_MINING = 1;  // 矿机（锁仓）
    
    public static function getMiningStatusMap(): array
    {
        return [
            self::MINING_STATUS_NORMAL => '正常',
            self::MINING_STATUS_MINING => '矿机运行中',
        ];
    }
    
    /**
     * 用户藏品提货状态
     * ba_user_collection.delivery_status
     */
    const DELIVERY_STATUS_NOT_DELIVERED = 0;  // 未提货
    const DELIVERY_STATUS_DELIVERED = 1;      // 已提货
    
    public static function getDeliveryStatusMap(): array
    {
        return [
            self::DELIVERY_STATUS_NOT_DELIVERED => '未提货',
            self::DELIVERY_STATUS_DELIVERED => '已提货',
        ];
    }
    
    // ============================================================
    // 订单相关状态
    // ============================================================
    
    /**
     * 藏品订单状态
     * ba_collection_order.status
     */
    const ORDER_STATUS_PENDING = 'pending';     // 待支付
    const ORDER_STATUS_PAID = 'paid';           // 已支付
    const ORDER_STATUS_COMPLETED = 'completed'; // 已完成
    const ORDER_STATUS_CANCELLED = 'cancelled'; // 已取消
    const ORDER_STATUS_REFUNDED = 'refunded';   // 已退款
    
    public static function getOrderStatusMap(): array
    {
        return [
            self::ORDER_STATUS_PENDING => '待支付',
            self::ORDER_STATUS_PAID => '已支付',
            self::ORDER_STATUS_COMPLETED => '已完成',
            self::ORDER_STATUS_CANCELLED => '已取消',
            self::ORDER_STATUS_REFUNDED => '已退款',
        ];
    }
    
    /**
     * 商城订单状态
     * ba_shop_order.status
     */
    const SHOP_ORDER_STATUS_PENDING = 'pending';     // 待支付
    const SHOP_ORDER_STATUS_PAID = 'paid';           // 已支付
    const SHOP_ORDER_STATUS_SHIPPED = 'shipped';     // 已发货
    const SHOP_ORDER_STATUS_COMPLETED = 'completed'; // 已完成
    const SHOP_ORDER_STATUS_CANCELLED = 'cancelled'; // 已取消
    const SHOP_ORDER_STATUS_REFUNDED = 'refunded';  // 已退款
    
    public static function getShopOrderStatusMap(): array
    {
        return [
            self::SHOP_ORDER_STATUS_PENDING => '待支付',
            self::SHOP_ORDER_STATUS_PAID => '待发货',
            self::SHOP_ORDER_STATUS_SHIPPED => '待确认收货',
            self::SHOP_ORDER_STATUS_COMPLETED => '已完成',
            self::SHOP_ORDER_STATUS_CANCELLED => '已取消',
            self::SHOP_ORDER_STATUS_REFUNDED => '已退款',
        ];
    }
    
    /**
     * 支付方式
     * ba_collection_order.pay_type, ba_shop_order.pay_type
     */
    const PAY_TYPE_MONEY = 'money';  // 余额支付
    const PAY_TYPE_SCORE = 'score';   // 消费金支付
    const PAY_TYPE_COMBINED = 'combined';   // 组合支付

    public static function getPayTypeMap(): array
    {
        return [
            self::PAY_TYPE_MONEY => '余额支付',
            self::PAY_TYPE_SCORE => '消费金支付',
            self::PAY_TYPE_COMBINED => '组合支付',
        ];
    }
    
    // ============================================================
    // 撮合池相关状态
    // ============================================================
    
    /**
     * 撮合池状态
     * ba_collection_matching_pool.status
     */
    const MATCHING_STATUS_PENDING = 'pending';   // 待撮合
    const MATCHING_STATUS_MATCHED = 'matched';   // 已撮合
    const MATCHING_STATUS_CANCELLED = 'cancelled'; // 已取消
    
    public static function getMatchingStatusMap(): array
    {
        return [
            self::MATCHING_STATUS_PENDING => '待撮合',
            self::MATCHING_STATUS_MATCHED => '已撮合',
            self::MATCHING_STATUS_CANCELLED => '已取消',
        ];
    }
    
    /**
     * 盲盒预约状态
     * ba_trade_reservations.status
     */
    const RESERVATION_STATUS_PENDING = 0;  // 待撮合
    const RESERVATION_STATUS_MATCHED = 1;  // 已中签
    const RESERVATION_STATUS_FAILED = 2;   // 未中签
    const RESERVATION_STATUS_CANCELLED = 3; // 已取消
    
    public static function getReservationStatusMap(): array
    {
        return [
            self::RESERVATION_STATUS_PENDING => '待撮合',
            self::RESERVATION_STATUS_MATCHED => '已中签',
            self::RESERVATION_STATUS_FAILED => '未中签',
            self::RESERVATION_STATUS_CANCELLED => '已取消',
        ];
    }
    
    // ============================================================
    // 场次相关状态
    // ============================================================
    
    /**
     * 场次状态
     * ba_collection_session.status
     */
    const SESSION_STATUS_OFFLINE = '0';  // 下架
    const SESSION_STATUS_ONLINE = '1';   // 上架
    
    public static function getSessionStatusMap(): array
    {
        return [
            self::SESSION_STATUS_OFFLINE => '下架',
            self::SESSION_STATUS_ONLINE => '上架',
        ];
    }
    
    // ============================================================
    // 用户相关状态
    // ============================================================
    
    /**
     * 实名认证状态
     * ba_user.real_name_status
     */
    const REAL_NAME_STATUS_NONE = 0;      // 未实名
    const REAL_NAME_STATUS_PENDING = 1;   // 待审核
    const REAL_NAME_STATUS_APPROVED = 2;  // 已通过
    const REAL_NAME_STATUS_REJECTED = 3;  // 已拒绝
    
    public static function getRealNameStatusMap(): array
    {
        return [
            self::REAL_NAME_STATUS_NONE => '未实名',
            self::REAL_NAME_STATUS_PENDING => '待审核',
            self::REAL_NAME_STATUS_APPROVED => '已通过',
            self::REAL_NAME_STATUS_REJECTED => '已拒绝',
        ];
    }
    
    /**
     * 用户状态
     * ba_user.status
     */
    const USER_STATUS_DISABLE = 'disable';  // 禁用
    const USER_STATUS_ENABLE = 'enable';   // 启用
    
    public static function getUserStatusMap(): array
    {
        return [
            self::USER_STATUS_DISABLE => '禁用',
            self::USER_STATUS_ENABLE => '启用',
        ];
    }
    
    // ============================================================
    // 资金相关类型
    // ============================================================
    
    /**
     * 资金类型（账户类型）
     * ba_user_money_log.field_type
     */
    const ACCOUNT_TYPE_BALANCE_AVAILABLE = 'balance_available';      // 专项金
    const ACCOUNT_TYPE_WITHDRAWABLE_MONEY = 'withdrawable_money';   // 可提现金额
    const ACCOUNT_TYPE_SERVICE_FEE_BALANCE = 'service_fee_balance'; // 确权金
    const ACCOUNT_TYPE_SCORE = 'score';                             // 消费金
    const ACCOUNT_TYPE_GREEN_POWER = 'green_power';                  // 绿色算力
    const ACCOUNT_TYPE_PENDING_ACTIVATION_GOLD = 'pending_activation_gold'; // 待激活确权金
    
    public static function getAccountTypeMap(): array
    {
        return [
            self::ACCOUNT_TYPE_BALANCE_AVAILABLE => '专项金',
            self::ACCOUNT_TYPE_WITHDRAWABLE_MONEY => '可提现金额',
            self::ACCOUNT_TYPE_SERVICE_FEE_BALANCE => '确权金',
            self::ACCOUNT_TYPE_SCORE => '消费金',
            self::ACCOUNT_TYPE_GREEN_POWER => '绿色算力',
            self::ACCOUNT_TYPE_PENDING_ACTIVATION_GOLD => '待激活确权金',
        ];
    }
    
    /**
     * 资金流向
     */
    const FLOW_DIRECTION_IN = 'in';   // 收入
    const FLOW_DIRECTION_OUT = 'out'; // 支出
    
    public static function getFlowDirectionMap(): array
    {
        return [
            self::FLOW_DIRECTION_IN => '收入',
            self::FLOW_DIRECTION_OUT => '支出',
        ];
    }
    
    // ============================================================
    // 业务类型
    // ============================================================
    
    /**
     * 资金流水业务类型
     * ba_user_money_log.biz_type
     */
    const BIZ_TYPE_REGISTER_REWARD = 'register_reward';           // 注册奖励
    const BIZ_TYPE_INVITE_REWARD = 'invite_reward';               // 邀请奖励
    const BIZ_TYPE_BLIND_BOX_RESERVE = 'blind_box_reserve';       // 盲盒预约
    const BIZ_TYPE_BLIND_BOX_REFUND = 'blind_box_refund';         // 盲盒退款
    const BIZ_TYPE_BLIND_BOX_DIFF_REFUND = 'blind_box_diff_refund'; // 盲盒差价退款
    const BIZ_TYPE_MATCHING_BUY = 'matching_buy';                 // 撮合购买
    const BIZ_TYPE_MATCHING_SELLER_INCOME = 'matching_seller_income'; // 撮合卖家收入
    const BIZ_TYPE_MATCHING_REFUND = 'matching_refund';           // 撮合退款
    const BIZ_TYPE_MATCHING_OFFICIAL_SELLER = 'matching_official_seller'; // 撮合交易
    const BIZ_TYPE_MATCHING_COMMISSION = 'matching_commission';   // 撮合佣金
    const BIZ_TYPE_CONSIGN_BUY = 'consign_buy';                   // 寄售购买
    const BIZ_TYPE_CONSIGN_SETTLE = 'consign_settle';             // 寄售结算
    const BIZ_TYPE_CONSIGN_SETTLE_SCORE = 'consign_settle_score'; // 寄售消费金结算
    const BIZ_TYPE_CONSIGN_APPLY_FEE = 'consign_apply_fee';       // 寄售申请费用
    const BIZ_TYPE_SHOP_ORDER = 'shop_order';                     // 商城订单
    const BIZ_TYPE_SHOP_ORDER_PAY = 'shop_order_pay';             // 商城订单支付
    const BIZ_TYPE_SERVICE_FEE_RECHARGE = 'service_fee_recharge'; // 确权金充值
    const BIZ_TYPE_SIGN_IN = 'sign_in';                           // 签到
    const BIZ_TYPE_OLD_ASSETS_UNLOCK = 'old_assets_unlock';       // 老资产解锁
    const BIZ_TYPE_SCORE_EXCHANGE_GREEN_POWER = 'score_exchange_green_power'; // 消费金兑换绿色算力
    
    public static function getBizTypeMap(): array
    {
        return [
            self::BIZ_TYPE_REGISTER_REWARD => '注册奖励',
            self::BIZ_TYPE_INVITE_REWARD => '邀请奖励',
            self::BIZ_TYPE_BLIND_BOX_RESERVE => '盲盒预约',
            self::BIZ_TYPE_BLIND_BOX_REFUND => '盲盒退款',
            self::BIZ_TYPE_BLIND_BOX_DIFF_REFUND => '盲盒差价退款',
            self::BIZ_TYPE_MATCHING_BUY => '撮合购买',
            self::BIZ_TYPE_MATCHING_SELLER_INCOME => '撮合卖家收入',
            self::BIZ_TYPE_MATCHING_REFUND => '撮合退款',
            self::BIZ_TYPE_MATCHING_OFFICIAL_SELLER => '撮合交易',
            self::BIZ_TYPE_MATCHING_COMMISSION => '撮合佣金',
            self::BIZ_TYPE_CONSIGN_BUY => '寄售购买',
            self::BIZ_TYPE_CONSIGN_SETTLE => '寄售结算',
            self::BIZ_TYPE_CONSIGN_SETTLE_SCORE => '寄售消费金结算',
            self::BIZ_TYPE_CONSIGN_APPLY_FEE => '寄售申请费用',
            self::BIZ_TYPE_SHOP_ORDER => '商城订单',
            self::BIZ_TYPE_SHOP_ORDER_PAY => '商城订单支付',
            self::BIZ_TYPE_SERVICE_FEE_RECHARGE => '确权金充值',
            self::BIZ_TYPE_SIGN_IN => '签到',
            self::BIZ_TYPE_OLD_ASSETS_UNLOCK => '老资产解锁',
            self::BIZ_TYPE_SCORE_EXCHANGE_GREEN_POWER => '消费金兑换绿色算力',
        ];
    }

    // ============================================================
    // 寄售券相关状态
    // ============================================================
    
    /**
     * 寄售券状态
     * ba_user_consignment_coupon.status
     */
    const COUPON_STATUS_USED = 0;     // 已使用
    const COUPON_STATUS_AVAILABLE = 1; // 可用
    const COUPON_STATUS_EXPIRED = 2;  // 已过期
    
    public static function getCouponStatusMap(): array
    {
        return [
            self::COUPON_STATUS_USED => '已使用',
            self::COUPON_STATUS_AVAILABLE => '可用',
            self::COUPON_STATUS_EXPIRED => '已过期',
        ];
    }
    
    // ============================================================
    // 资产包相关状态
    // ============================================================
    
    /**
     * 资产包状态
     * ba_asset_package.status
     */
    const ASSET_PACKAGE_STATUS_DISABLED = 0;  // 关闭
    const ASSET_PACKAGE_STATUS_ENABLED = 1;   // 开启
    
    public static function getAssetPackageStatusMap(): array
    {
        return [
            self::ASSET_PACKAGE_STATUS_DISABLED => '关闭',
            self::ASSET_PACKAGE_STATUS_ENABLED => '开启',
        ];
    }
    
    // ============================================================
    // 寄售豁免类型
    // ============================================================
    
    /**
     * 寄售券豁免类型
     * ba_collection_consignment.waive_type
     */
    const WAIVE_TYPE_NONE = 'none';              // 未豁免（正常扣券）
    const WAIVE_TYPE_SYSTEM_RESEND = 'system_resend'; // 系统重发（流拍免费重发）
    const WAIVE_TYPE_FREE_ATTEMPT = 'free_attempt';   // 免费次数
    
    public static function getWaiveTypeMap(): array
    {
        return [
            self::WAIVE_TYPE_NONE => '正常扣券',
            self::WAIVE_TYPE_SYSTEM_RESEND => '系统重发',
            self::WAIVE_TYPE_FREE_ATTEMPT => '免费次数',
        ];
    }
    
    // ============================================================
    // 结算状态
    // ============================================================
    
    /**
     * 寄售结算状态
     * ba_collection_consignment.settle_status
     */
    const SETTLE_STATUS_UNSETTLED = 0;  // 未结算
    const SETTLE_STATUS_SETTLED = 1;    // 已结算
    
    public static function getSettleStatusMap(): array
    {
        return [
            self::SETTLE_STATUS_UNSETTLED => '未结算',
            self::SETTLE_STATUS_SETTLED => '已结算',
        ];
    }
    
    // ============================================================
    // 工具方法
    // ============================================================
    
    /**
     * 获取状态文本
     * @param string $type 类型（如：consignment_status, order_status等）
     * @param mixed $value 状态值
     * @return string
     */
    public static function getStatusText(string $type, $value): string
    {
        $map = self::getMapByType($type);
        return $map[$value] ?? '未知';
    }
    
    /**
     * 根据类型获取映射数组
     * @param string $type
     * @return array
     */
    public static function getMapByType(string $type): array
    {
        return match($type) {
            'item_status' => self::getItemStatusMap(),
            'consignment_status' => self::getConsignmentStatusMap(),
            'consignment_record_status' => self::getConsignmentRecordStatusMap(),
            'mining_status' => self::getMiningStatusMap(),
            'delivery_status' => self::getDeliveryStatusMap(),
            'order_status' => self::getOrderStatusMap(),
            'shop_order_status' => self::getShopOrderStatusMap(),
            'pay_type' => self::getPayTypeMap(),
            'matching_status' => self::getMatchingStatusMap(),
            'reservation_status' => self::getReservationStatusMap(),
            'session_status' => self::getSessionStatusMap(),
            'real_name_status' => self::getRealNameStatusMap(),
            'user_status' => self::getUserStatusMap(),
            'account_type' => self::getAccountTypeMap(),
            'flow_direction' => self::getFlowDirectionMap(),
            'biz_type' => self::getBizTypeMap(),
            'coupon_status' => self::getCouponStatusMap(),

            'asset_package_status' => self::getAssetPackageStatusMap(),
            'waive_type' => self::getWaiveTypeMap(),
            'settle_status' => self::getSettleStatusMap(),
            default => [],
        };
    }
    
    /**
     * 获取所有映射字典（用于API返回）
     * @return array
     */
    public static function getAllMaps(): array
    {
        return [
            'item_status' => self::getItemStatusMap(),
            'consignment_status' => self::getConsignmentStatusMap(),
            'consignment_record_status' => self::getConsignmentRecordStatusMap(),
            'mining_status' => self::getMiningStatusMap(),
            'delivery_status' => self::getDeliveryStatusMap(),
            'order_status' => self::getOrderStatusMap(),
            'shop_order_status' => self::getShopOrderStatusMap(),
            'pay_type' => self::getPayTypeMap(),
            'matching_status' => self::getMatchingStatusMap(),
            'reservation_status' => self::getReservationStatusMap(),
            'session_status' => self::getSessionStatusMap(),
            'real_name_status' => self::getRealNameStatusMap(),
            'user_status' => self::getUserStatusMap(),
            'account_type' => self::getAccountTypeMap(),
            'flow_direction' => self::getFlowDirectionMap(),
            'biz_type' => self::getBizTypeMap(),
            'coupon_status' => self::getCouponStatusMap(),

            'asset_package_status' => self::getAssetPackageStatusMap(),
            'waive_type' => self::getWaiveTypeMap(),
            'settle_status' => self::getSettleStatusMap(),
        ];
    }
}
