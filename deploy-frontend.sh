# 部署构建产物到生产目录
echo "部署前端构建产物..."

# 删除旧文件
[ -f "public/index.html" ] && rm -f public/index.html
[ -d "public/assets" ] && rm -rf public/assets

# 复制新文件
if cp -r web/dist/* public/; then
    echo "✅ 部署成功！"
    echo "构建产物已复制到 public/ 目录"
else
    echo "❌ 部署失败！"
fi
