#!/bin/bash
# 修复 runtime 目录权限脚本
# 用途：当出现日志写入权限问题时运行此脚本

echo "=== 修复 runtime 目录权限 ==="

# 修复所有者
chown -R www:www runtime/

# 修复目录权限
find runtime/ -type d -exec chmod 755 {} \;

# 修复文件权限
find runtime/ -type f -exec chmod 644 {} \;

echo "✅ 权限修复完成"
echo ""
echo "当前 runtime 目录权限："
ls -lh runtime/ | head -10
