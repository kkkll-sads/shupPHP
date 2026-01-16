# Archive 归档目录

本目录用于存放临时脚本和导出文件的归档。

## 目录结构

```
archive/
├── temp_scripts/     # 临时脚本归档（已完成任务的一次性脚本）
└── exports/          # 临时导出文件归档
```

## temp_scripts/ 临时脚本说明

这些脚本是为特定的维护任务创建的一次性脚本，已完成任务后归档保存：

- **add_user_collection_menu.php** - 添加用户持仓管理菜单
- **batch_change_inviter.php** - 批量修改用户上级邀请人
- **check_user_*.php** - 各种用户数据检查脚本
- **compensate_jan7_users.php** - 1月7日用户补偿脚本
- **export_*.php** - 各种数据导出脚本
- **fix_*.php** - 各种数据修复脚本
- **global_fix_invite_reward_power.php** - 全局修复邀请奖励算力
- **import_tracking_auto.php** - 自动导入物流单号
- **retroactive_*.php** - 各种追溯奖励脚本

## exports/ 导出文件说明

临时导出的数据文件归档：

- **未发货订单_*.csv** - 未发货订单导出
- **用户资金统计_*.csv** - 用户资金统计导出

## 注意事项

1. **不要删除归档文件**：这些文件可能在后续需要时作为参考
2. **如需重新执行**：可以从归档目录复制脚本到项目根目录执行
3. **定期清理**：建议每季度检查一次，清理超过6个月的归档文件

## 归档时间

2026-01-14 21:45
