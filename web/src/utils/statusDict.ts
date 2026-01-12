/**
 * 前后端统一状态映射字典
 * 
 * 用于统一前后端的状态码、枚举值、文本映射
 * 所有状态相关的映射都应在此定义，确保前后端一致
 * 
 * 注意：此文件应与后端 app/common/library/StatusDict.php 保持一致
 */

// ============================================================
// 藏品相关状态
// ============================================================

/**
 * 藏品商品上架状态
 * ba_collection_item.status
 */
export enum ItemStatus {
  OFFLINE = '0',  // 下架
  ONLINE = '1',   // 上架
}

export const ItemStatusMap: Record<ItemStatus, string> = {
  [ItemStatus.OFFLINE]: '下架',
  [ItemStatus.ONLINE]: '上架',
}

/**
 * 用户藏品寄售状态
 * ba_user_collection.consignment_status
 */
export enum ConsignmentStatus {
  NONE = 0,      // 未寄售
  SELLING = 1,   // 寄售中
  SOLD = 2,      // 已售出
  FAILED = 3,    // 寄售失败
}

export const ConsignmentStatusMap: Record<ConsignmentStatus, string> = {
  [ConsignmentStatus.NONE]: '未寄售',
  [ConsignmentStatus.SELLING]: '寄售中',
  [ConsignmentStatus.SOLD]: '已售出',
  [ConsignmentStatus.FAILED]: '寄售失败',
}

/**
 * 寄售记录状态
 * ba_collection_consignment.status
 */
export enum ConsignmentRecordStatus {
  CANCELLED = 0,  // 已取消
  SELLING = 1,    // 寄售中
  SOLD = 2,       // 已售出
  OFF_SHELF = 3,  // 已下架/流拍
}

export const ConsignmentRecordStatusMap: Record<ConsignmentRecordStatus, string> = {
  [ConsignmentRecordStatus.CANCELLED]: '已取消',
  [ConsignmentRecordStatus.SELLING]: '寄售中',
  [ConsignmentRecordStatus.SOLD]: '已售出',
  [ConsignmentRecordStatus.OFF_SHELF]: '流拍失败',
}

/**
 * 用户藏品矿机状态
 * ba_user_collection.mining_status
 */
export enum MiningStatus {
  NORMAL = 0,  // 正常
  MINING = 1,  // 矿机（锁仓）
}

export const MiningStatusMap: Record<MiningStatus, string> = {
  [MiningStatus.NORMAL]: '正常',
  [MiningStatus.MINING]: '矿机运行中',
}

/**
 * 用户藏品提货状态
 * ba_user_collection.delivery_status
 */
export enum DeliveryStatus {
  NOT_DELIVERED = 0,  // 未提货
  DELIVERED = 1,      // 已提货
}

export const DeliveryStatusMap: Record<DeliveryStatus, string> = {
  [DeliveryStatus.NOT_DELIVERED]: '未提货',
  [DeliveryStatus.DELIVERED]: '已提货',
}

// ============================================================
// 订单相关状态
// ============================================================

/**
 * 藏品订单状态
 * ba_collection_order.status
 */
export enum OrderStatus {
  PENDING = 'pending',     // 待支付
  PAID = 'paid',           // 已支付
  COMPLETED = 'completed', // 已完成
  CANCELLED = 'cancelled', // 已取消
  REFUNDED = 'refunded',   // 已退款
}

export const OrderStatusMap: Record<OrderStatus, string> = {
  [OrderStatus.PENDING]: '待支付',
  [OrderStatus.PAID]: '已支付',
  [OrderStatus.COMPLETED]: '已完成',
  [OrderStatus.CANCELLED]: '已取消',
  [OrderStatus.REFUNDED]: '已退款',
}

/**
 * 商城订单状态
 * ba_shop_order.status
 */
export enum ShopOrderStatus {
  PENDING = 'pending',     // 待支付
  PAID = 'paid',           // 已支付
  SHIPPED = 'shipped',     // 已发货
  COMPLETED = 'completed', // 已完成
  CANCELLED = 'cancelled', // 已取消
  REFUNDED = 'refunded',   // 已退款
}

export const ShopOrderStatusMap: Record<ShopOrderStatus, string> = {
  [ShopOrderStatus.PENDING]: '待支付',
  [ShopOrderStatus.PAID]: '待发货',
  [ShopOrderStatus.SHIPPED]: '待确认收货',
  [ShopOrderStatus.COMPLETED]: '已完成',
  [ShopOrderStatus.CANCELLED]: '已取消',
  [ShopOrderStatus.REFUNDED]: '已退款',
}

/**
 * 支付方式
 * ba_collection_order.pay_type, ba_shop_order.pay_type
 */
export enum PayType {
  MONEY = 'money',  // 余额支付
  SCORE = 'score',  // 消费金支付
}

export const PayTypeMap: Record<PayType, string> = {
  [PayType.MONEY]: '余额支付',
  [PayType.SCORE]: '消费金支付',
}

// ============================================================
// 撮合池相关状态
// ============================================================

/**
 * 撮合池状态
 * ba_collection_matching_pool.status
 */
export enum MatchingStatus {
  PENDING = 'pending',   // 待撮合
  MATCHED = 'matched',   // 已撮合
  CANCELLED = 'cancelled', // 已取消
}

export const MatchingStatusMap: Record<MatchingStatus, string> = {
  [MatchingStatus.PENDING]: '待撮合',
  [MatchingStatus.MATCHED]: '已撮合',
  [MatchingStatus.CANCELLED]: '已取消',
}

/**
 * 盲盒预约状态
 * ba_trade_reservations.status
 */
export enum ReservationStatus {
  PENDING = 0,  // 待撮合
  MATCHED = 1,  // 已中签
  FAILED = 2,   // 未中签
  CANCELLED = 3, // 已取消
}

export const ReservationStatusMap: Record<ReservationStatus, string> = {
  [ReservationStatus.PENDING]: '待撮合',
  [ReservationStatus.MATCHED]: '已中签',
  [ReservationStatus.FAILED]: '未中签',
  [ReservationStatus.CANCELLED]: '已取消',
}

// ============================================================
// 场次相关状态
// ============================================================

/**
 * 场次状态
 * ba_collection_session.status
 */
export enum SessionStatus {
  OFFLINE = '0',  // 下架
  ONLINE = '1',   // 上架
}

export const SessionStatusMap: Record<SessionStatus, string> = {
  [SessionStatus.OFFLINE]: '下架',
  [SessionStatus.ONLINE]: '上架',
}

// ============================================================
// 用户相关状态
// ============================================================

/**
 * 实名认证状态
 * ba_user.real_name_status
 */
export enum RealNameStatus {
  NONE = 0,      // 未实名
  PENDING = 1,   // 待审核
  APPROVED = 2,  // 已通过
  REJECTED = 3,  // 已拒绝
}

export const RealNameStatusMap: Record<RealNameStatus, string> = {
  [RealNameStatus.NONE]: '未实名',
  [RealNameStatus.PENDING]: '待审核',
  [RealNameStatus.APPROVED]: '已通过',
  [RealNameStatus.REJECTED]: '已拒绝',
}

/**
 * 用户状态
 * ba_user.status
 */
export enum UserStatus {
  DISABLE = 'disable',  // 禁用
  ENABLE = 'enable',    // 启用
}

export const UserStatusMap: Record<UserStatus, string> = {
  [UserStatus.DISABLE]: '禁用',
  [UserStatus.ENABLE]: '启用',
}

// ============================================================
// 资金相关类型
// ============================================================

/**
 * 资金类型（账户类型）
 * ba_user_money_log.field_type
 */
export enum AccountType {
  BALANCE_AVAILABLE = 'balance_available',      // 专项金
  WITHDRAWABLE_MONEY = 'withdrawable_money',   // 可提现金额
  SERVICE_FEE_BALANCE = 'service_fee_balance', // 确权金
  SCORE = 'score',                             // 消费金
  GREEN_POWER = 'green_power',                  // 绿色算力
  PENDING_ACTIVATION_GOLD = 'pending_activation_gold', // 待激活确权金
}

export const AccountTypeMap: Record<AccountType, string> = {
  [AccountType.BALANCE_AVAILABLE]: '专项金',
  [AccountType.WITHDRAWABLE_MONEY]: '可提现金额',
  [AccountType.SERVICE_FEE_BALANCE]: '确权金',
  [AccountType.SCORE]: '消费金',
  [AccountType.GREEN_POWER]: '绿色算力',
  [AccountType.PENDING_ACTIVATION_GOLD]: '待激活确权金',
}

/**
 * 资金流向
 */
export enum FlowDirection {
  IN = 'in',   // 收入
  OUT = 'out', // 支出
}

export const FlowDirectionMap: Record<FlowDirection, string> = {
  [FlowDirection.IN]: '收入',
  [FlowDirection.OUT]: '支出',
}

// ============================================================
// 业务类型
// ============================================================

/**
 * 资金流水业务类型
 * ba_user_money_log.biz_type
 */
export enum BizType {
  REGISTER_REWARD = 'register_reward',           // 注册奖励
  INVITE_REWARD = 'invite_reward',               // 邀请奖励
  BLIND_BOX_RESERVE = 'blind_box_reserve',       // 盲盒预约
  BLIND_BOX_REFUND = 'blind_box_refund',         // 盲盒退款
  BLIND_BOX_DIFF_REFUND = 'blind_box_diff_refund', // 盲盒差价退款
  MATCHING_BUY = 'matching_buy',                 // 撮合购买
  MATCHING_SELLER_INCOME = 'matching_seller_income', // 撮合卖家收入
  MATCHING_REFUND = 'matching_refund',           // 撮合退款
  MATCHING_OFFICIAL_SELLER = 'matching_official_seller', // 撮合交易
  MATCHING_COMMISSION = 'matching_commission',   // 撮合佣金
  CONSIGN_BUY = 'consign_buy',                   // 寄售购买
  CONSIGN_SETTLE = 'consign_settle',             // 寄售结算
  CONSIGN_SETTLE_SCORE = 'consign_settle_score', // 寄售消费金结算
  CONSIGN_APPLY_FEE = 'consign_apply_fee',       // 寄售申请费用
  SHOP_ORDER = 'shop_order',                     // 商城订单
  SHOP_ORDER_PAY = 'shop_order_pay',             // 商城订单支付
  SERVICE_FEE_RECHARGE = 'service_fee_recharge', // 确权金充值
  SIGN_IN = 'sign_in',                           // 签到
  OLD_ASSETS_UNLOCK = 'old_assets_unlock',       // 老资产解锁
  SCORE_EXCHANGE_GREEN_POWER = 'score_exchange_green_power', // 消费金兑换绿色算力
}

export const BizTypeMap: Record<BizType, string> = {
  [BizType.REGISTER_REWARD]: '注册奖励',
  [BizType.INVITE_REWARD]: '邀请奖励',
  [BizType.BLIND_BOX_RESERVE]: '盲盒预约',
  [BizType.BLIND_BOX_REFUND]: '盲盒退款',
  [BizType.BLIND_BOX_DIFF_REFUND]: '盲盒差价退款',
  [BizType.MATCHING_BUY]: '撮合购买',
  [BizType.MATCHING_SELLER_INCOME]: '撮合卖家收入',
  [BizType.MATCHING_REFUND]: '撮合退款',
  [BizType.MATCHING_OFFICIAL_SELLER]: '撮合交易',
  [BizType.MATCHING_COMMISSION]: '撮合佣金',
  [BizType.CONSIGN_BUY]: '寄售购买',
  [BizType.CONSIGN_SETTLE]: '寄售结算',
  [BizType.CONSIGN_SETTLE_SCORE]: '寄售消费金结算',
  [BizType.CONSIGN_APPLY_FEE]: '寄售申请费用',
  [BizType.SHOP_ORDER]: '商城订单',
  [BizType.SHOP_ORDER_PAY]: '商城订单支付',
  [BizType.SERVICE_FEE_RECHARGE]: '确权金充值',
  [BizType.SIGN_IN]: '签到',
  [BizType.OLD_ASSETS_UNLOCK]: '老资产解锁',
  [BizType.SCORE_EXCHANGE_GREEN_POWER]: '消费金兑换绿色算力',
}

// ============================================================
// 寄售券相关状态
// ============================================================

/**
 * 寄售券状态
 * ba_user_consignment_coupon.status
 */
export enum CouponStatus {
  USED = 0,     // 已使用
  AVAILABLE = 1, // 可用
  EXPIRED = 2,  // 已过期
}

export const CouponStatusMap: Record<CouponStatus, string> = {
  [CouponStatus.USED]: '已使用',
  [CouponStatus.AVAILABLE]: '可用',
  [CouponStatus.EXPIRED]: '已过期',
}

// ============================================================
// 资产包相关状态
// ============================================================

/**
 * 资产包状态
 * ba_asset_package.status
 */
export enum AssetPackageStatus {
  DISABLED = 0,  // 关闭
  ENABLED = 1,   // 开启
}

export const AssetPackageStatusMap: Record<AssetPackageStatus, string> = {
  [AssetPackageStatus.DISABLED]: '关闭',
  [AssetPackageStatus.ENABLED]: '开启',
}

// ============================================================
// 寄售豁免类型
// ============================================================

/**
 * 寄售券豁免类型
 * ba_collection_consignment.waive_type
 */
export enum WaiveType {
  NONE = 'none',              // 未豁免（正常扣券）
  SYSTEM_RESEND = 'system_resend', // 系统重发（流拍免费重发）
  FREE_ATTEMPT = 'free_attempt',   // 免费次数
}

export const WaiveTypeMap: Record<WaiveType, string> = {
  [WaiveType.NONE]: '正常扣券',
  [WaiveType.SYSTEM_RESEND]: '系统重发',
  [WaiveType.FREE_ATTEMPT]: '免费次数',
}

// ============================================================
// 结算状态
// ============================================================

/**
 * 寄售结算状态
 * ba_collection_consignment.settle_status
 */
export enum SettleStatus {
  UNSETTLED = 0,  // 未结算
  SETTLED = 1,    // 已结算
}

export const SettleStatusMap: Record<SettleStatus, string> = {
  [SettleStatus.UNSETTLED]: '未结算',
  [SettleStatus.SETTLED]: '已结算',
}

// ============================================================
// 工具方法
// ============================================================

/**
 * 获取状态文本
 * @param map 映射对象
 * @param value 状态值
 * @param defaultValue 默认值
 * @returns 状态文本
 */
export function getStatusText<T extends string | number>(
  map: Record<T, string>,
  value: T,
  defaultValue: string = '未知'
): string {
  return map[value] ?? defaultValue
}

/**
 * 获取所有映射字典
 * @returns 所有映射字典对象
 */
export function getAllMaps() {
  return {
    item_status: ItemStatusMap,
    consignment_status: ConsignmentStatusMap,
    consignment_record_status: ConsignmentRecordStatusMap,
    mining_status: MiningStatusMap,
    delivery_status: DeliveryStatusMap,
    order_status: OrderStatusMap,
    shop_order_status: ShopOrderStatusMap,
    pay_type: PayTypeMap,
    matching_status: MatchingStatusMap,
    reservation_status: ReservationStatusMap,
    session_status: SessionStatusMap,
    real_name_status: RealNameStatusMap,
    user_status: UserStatusMap,
    account_type: AccountTypeMap,
    flow_direction: FlowDirectionMap,
    biz_type: BizTypeMap,
    coupon_status: CouponStatusMap,
    asset_package_status: AssetPackageStatusMap,
    waive_type: WaiveTypeMap,
    settle_status: SettleStatusMap,
  }
}

