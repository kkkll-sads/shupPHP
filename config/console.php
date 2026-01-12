<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'finance:income:daily' => 'app\command\FinanceIncomeDaily',
        'finance:income:period' => 'app\command\FinanceIncomePeriod',
        'finance:income:stage' => 'app\command\FinanceIncomeStage',
        'finance:settle' => 'app\command\FinanceOrderSettle',
        'add:score:coupon' => 'app\command\AddUserScoreAndCoupon',
        'consignment:expire' => 'app\command\ConsignmentExpire',
        'collection:mining:check' => 'app\command\CollectionMiningCheck',
        'collection:mining:dividend' => 'app\command\CollectionMiningDividend',
        'collection:matching' => 'app\command\CollectionMatching',
        'collection:daily:dividend' => 'app\command\CollectionDailyDividend',
        'consignment:fix-settlement' => 'app\command\FixConsignmentSettlement',
        'diagnose:zone' => 'app\command\DiagnoseCrossZone',
    ],
];
