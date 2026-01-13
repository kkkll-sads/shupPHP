<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddWithdrawFeeConfig extends Migrator
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
     *
     * The following commands can be used in this method and Phinx will
     * automatically reverse them when rolling back:
     *
     *    createTable
     *    renameTable
     *    addColumn
     *    renameColumn
     *    addIndex
     *    addForeignKey
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change()
    {
        // 检查配置是否已存在
        $exists = $this->fetchRow("SELECT COUNT(*) as count FROM ba_config WHERE name = 'withdraw_fee_rate'");
        
        if ($exists && $exists['count'] > 0) {
            // 配置已存在，跳过
            return;
        }

        // 插入提现手续费率配置
        $this->execute("
            INSERT INTO ba_config (name, `group`, title, tip, type, value, content, rule, extend, weigh, allow_del) 
            VALUES 
            (
                'withdraw_fee_rate',
                'finance',
                '提现手续费率',
                '按提现金额收取的手续费比例，单位：百分比。例如：2 表示收取2%的手续费',
                'number',
                '0',
                '',
                '',
                '',
                100,
                1
            )
        ");

        // 插入固定手续费配置
        $this->execute("
            INSERT INTO ba_config (name, `group`, title, tip, type, value, content, rule, extend, weigh, allow_del) 
            VALUES 
            (
                'withdraw_fixed_fee',
                'finance',
                '提现固定手续费',
                '每笔提现固定收取的手续费，单位：元。例如：2 表示每笔提现固定收取2元手续费',
                'number',
                '0',
                '',
                '',
                '',
                99,
                1
            )
        ");

        // 确保 finance 分组存在于配置分组中
        $this->execute("
            UPDATE ba_config 
            SET value = CONCAT(
                value,
                CASE 
                    WHEN value NOT LIKE '%finance%' THEN ',{\"key\":\"finance\",\"value\":\"财务配置\"}'
                    ELSE ''
                END
            )
            WHERE name = 'config_group' AND type = 'array'
        ");
    }
}
