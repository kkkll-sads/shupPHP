#!/bin/bash
# 撮合脚本执行包装器
# 用于方便执行 ThinkPHP 命令

cd /www/wwwroot/23.248.226.82

# 执行撮合命令
php -r "require 'vendor/autoload.php'; \$app = new think\App(); \$app->console->run();" collection:matching "$@"
