#!/bin/bash

# 前端一键编译更新脚本（包含数据库备份功能）
# 使用方法: ./build-frontend.sh
# 功能：
#   1. 自动备份数据库到当前目录（构建前）
#   2. 检查并修复 storage 目录权限（所有者: www:www, 权限: 755）
#   3. 清理 /tmp 目录中由 www 用户创建的旧临时文件
#   4. 构建前端项目
#   5. 部署构建产物到生产目录

set -e  # 遇到错误立即退出

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 项目根目录
PROJECT_ROOT="/www/wwwroot/95.40.167.213_3002"
WEB_DIR="${PROJECT_ROOT}/web"
PUBLIC_DIR="${PROJECT_ROOT}/public"
BUILD_LOG="${PROJECT_ROOT}/build-frontend.log"

# 数据库配置（从 .env 文件读取，如果没有则使用默认值）
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-waibao}"
DB_USER="${DB_USER:-waibao}"
DB_PASS="${DB_PASS:-weHPjtkrbAPSMCNm}"

# 日志函数
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1" | tee -a "$BUILD_LOG"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1" | tee -a "$BUILD_LOG"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1" | tee -a "$BUILD_LOG"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1" | tee -a "$BUILD_LOG"
}

# 检查命令是否存在
check_command() {
    if ! command -v $1 &> /dev/null; then
        log_error "$1 未安装，请先安装 $1"
        exit 1
    fi
}

# 读取 .env 文件中的数据库配置
load_env_config() {
    local env_file="${PROJECT_ROOT}/.env"
    
    # 先设置默认值（从 config/database.php 中的默认值）
    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_PORT="${DB_PORT:-3306}"
    DB_NAME="${DB_NAME:-waibao}"
    DB_USER="${DB_USER:-waibao}"
    DB_PASS="${DB_PASS:-weHPjtkrbAPSMCNm}"
    
    if [ -f "$env_file" ]; then
        log_info "读取 .env 文件配置..."
        
        # 支持两种格式：
        # 1. database.hostname = value （点号格式）
        # 2. [DATABASE] 段下的 hostname = value （ThinkPHP 段格式）
        
        # 尝试读取点号格式
        local tmp_host=$(grep -E "^database\.hostname\s*=" "$env_file" 2>/dev/null | head -1 | cut -d '=' -f2- | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || true)
        local tmp_port=$(grep -E "^database\.hostport\s*=" "$env_file" 2>/dev/null | head -1 | cut -d '=' -f2- | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || true)
        local tmp_name=$(grep -E "^database\.database\s*=" "$env_file" 2>/dev/null | head -1 | cut -d '=' -f2- | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || true)
        local tmp_user=$(grep -E "^database\.username\s*=" "$env_file" 2>/dev/null | head -1 | cut -d '=' -f2- | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || true)
        local tmp_pass=$(grep -E "^database\.password\s*=" "$env_file" 2>/dev/null | head -1 | cut -d '=' -f2- | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || true)
        
        # 如果点号格式没有找到，尝试读取段格式（[DATABASE] 或 [database]）
        if [ -z "$tmp_host" ]; then
            tmp_host=$(awk '/^\[(DATABASE|database)\]/,/^\[/ {if ($1 == "hostname" && $2 == "=") print $3}' "$env_file" 2>/dev/null | head -1 || true)
        fi
        if [ -z "$tmp_port" ]; then
            tmp_port=$(awk '/^\[(DATABASE|database)\]/,/^\[/ {if ($1 == "hostport" && $2 == "=") print $3}' "$env_file" 2>/dev/null | head -1 || true)
        fi
        if [ -z "$tmp_name" ]; then
            tmp_name=$(awk '/^\[(DATABASE|database)\]/,/^\[/ {if ($1 == "database" && $2 == "=") print $3}' "$env_file" 2>/dev/null | head -1 || true)
        fi
        if [ -z "$tmp_user" ]; then
            tmp_user=$(awk '/^\[(DATABASE|database)\]/,/^\[/ {if ($1 == "username" && $2 == "=") print $3}' "$env_file" 2>/dev/null | head -1 || true)
        fi
        if [ -z "$tmp_pass" ]; then
            tmp_pass=$(awk '/^\[(DATABASE|database)\]/,/^\[/ {if ($1 == "password" && $2 == "=") print $3}' "$env_file" 2>/dev/null | head -1 || true)
        fi
        
        # 如果读取到的值不为空，则覆盖默认值
        [ -n "$tmp_host" ] && DB_HOST="$tmp_host"
        [ -n "$tmp_port" ] && DB_PORT="$tmp_port"
        [ -n "$tmp_name" ] && DB_NAME="$tmp_name"
        [ -n "$tmp_user" ] && DB_USER="$tmp_user"
        [ -n "$tmp_pass" ] && DB_PASS="$tmp_pass"
        
        log_info "数据库配置已加载：$DB_HOST:$DB_PORT/$DB_NAME (用户: $DB_USER)"
    else
        log_warning ".env 文件不存在，使用默认数据库配置（来自 config/database.php）"
        log_info "数据库配置：$DB_HOST:$DB_PORT/$DB_NAME (用户: $DB_USER)"
    fi
}

# 检查和修复 storage 目录权限
fix_storage_permissions() {
    log_info "=========================================="
    log_info "检查 storage 目录权限..."
    log_info "=========================================="
    
    STORAGE_DIR="${PUBLIC_DIR}/storage"
    
    # 检查目录是否存在
    if [ ! -d "$STORAGE_DIR" ]; then
        log_warning "storage 目录不存在: $STORAGE_DIR"
        log_info "创建 storage 目录..."
        mkdir -p "$STORAGE_DIR"
    fi
    
    # 获取当前权限（八进制格式）
    CURRENT_PERM=$(stat -c "%a" "$STORAGE_DIR" 2>/dev/null || echo "000")
    CURRENT_OWNER=$(stat -c "%U:%G" "$STORAGE_DIR" 2>/dev/null || echo "unknown:unknown")
    
    log_info "当前目录: $STORAGE_DIR"
    log_info "当前权限: $CURRENT_PERM"
    log_info "当前所有者: $CURRENT_OWNER"
    
    # 检查权限是否为 755
    NEED_FIX=false
    
    if [ "$CURRENT_PERM" != "755" ]; then
        log_warning "目录权限不是 755，需要修复"
        NEED_FIX=true
    fi
    
    # 检查所有者是否为 www:www
    if [ "$CURRENT_OWNER" != "www:www" ]; then
        log_warning "目录所有者不是 www:www，需要修复"
        NEED_FIX=true
    fi
    
    if [ "$NEED_FIX" = true ]; then
        log_info "修复目录权限..."
        
        # 修复所有者和权限
        if chown -R www:www "$STORAGE_DIR" 2>/dev/null; then
            log_success "目录所有者已设置为 www:www"
        else
            log_error "设置目录所有者失败，可能需要 root 权限"
            return 1
        fi
        
        if chmod -R 755 "$STORAGE_DIR" 2>/dev/null; then
            log_success "目录权限已设置为 755"
        else
            log_error "设置目录权限失败"
            return 1
        fi
        
        # 验证修复结果
        NEW_PERM=$(stat -c "%a" "$STORAGE_DIR" 2>/dev/null)
        NEW_OWNER=$(stat -c "%U:%G" "$STORAGE_DIR" 2>/dev/null)
        
        log_info "修复后权限: $NEW_PERM"
        log_info "修复后所有者: $NEW_OWNER"
        
        if [ "$NEW_PERM" = "755" ] && [ "$NEW_OWNER" = "www:www" ]; then
            log_success "storage 目录权限修复成功！"
        else
            log_warning "权限修复可能不完整，请手动检查"
        fi
    else
        log_success "storage 目录权限正确，无需修复"
    fi
    
    return 0
}

# 清理临时文件
cleanup_temp_files() {
    log_info "=========================================="
    log_info "清理临时文件..."
    log_info "=========================================="
    
    # 清理 /tmp 目录中由 www 用户创建的旧临时文件（超过1天的）
    if [ -d "/tmp" ]; then
        log_info "扫描 /tmp 目录中 www 用户的临时文件..."
        
        # 查找并删除超过1天的临时文件
        TEMP_FILES=$(find /tmp -user www -type f -mtime +1 2>/dev/null | head -50)
        
        if [ -n "$TEMP_FILES" ]; then
            TEMP_COUNT=$(echo "$TEMP_FILES" | wc -l)
            log_info "找到 $TEMP_COUNT 个需要清理的临时文件"
            
            # 删除文件并统计成功删除的数量
            DELETED_COUNT=0
            echo "$TEMP_FILES" | while read -r file; do
                if rm -f "$file" 2>/dev/null; then
                    DELETED_COUNT=$((DELETED_COUNT + 1))
                fi
            done
            
            # 由于 while 循环在子 shell 中，重新计算实际删除的文件数
            REMAINING_COUNT=$(find /tmp -user www -type f -mtime +1 2>/dev/null | wc -l)
            DELETED_COUNT=$((TEMP_COUNT - REMAINING_COUNT))
            
            if [ $DELETED_COUNT -gt 0 ]; then
                log_success "已清理 $DELETED_COUNT 个临时文件"
            else
                log_info "清理完成（可能部分文件已被其他进程删除）"
            fi
        else
            log_info "未找到需要清理的临时文件"
        fi
        
        # 清理 PHP 临时文件（以 php 开头的文件，超过1天）
        PHP_TEMP_FILES=$(find /tmp -user www -type f -name "php*" -mtime +1 2>/dev/null | head -50)
        
        if [ -n "$PHP_TEMP_FILES" ]; then
            PHP_COUNT=$(echo "$PHP_TEMP_FILES" | wc -l)
            log_info "找到 $PHP_COUNT 个 PHP 临时文件需要清理"
            
            echo "$PHP_TEMP_FILES" | xargs rm -f 2>/dev/null || true
            log_success "已清理 $PHP_COUNT 个 PHP 临时文件"
        fi
    else
        log_warning "/tmp 目录不存在"
    fi
    
    log_success "临时文件清理完成！"
    return 0
}

# 备份数据库
backup_database() {
    log_info "=========================================="
    log_info "开始备份数据库..."
    log_info "=========================================="
    
    # 检查 mysqldump 命令
    if ! command -v mysqldump &> /dev/null; then
        log_error "mysqldump 未安装，无法备份数据库"
        log_warning "请安装 MySQL 客户端工具: yum install mysql -y 或 apt-get install mysql-client -y"
        return 1
    fi
    
    # 生成备份文件名（包含时间戳）
    BACKUP_DIR="${PROJECT_ROOT}"
    BACKUP_FILE="${BACKUP_DIR}/db_backup_${DB_NAME}_$(date +%Y%m%d_%H%M%S).sql"
    BACKUP_ERROR_LOG="${PROJECT_ROOT}/backup_error.log"
    
    log_info "数据库主机: $DB_HOST:$DB_PORT"
    log_info "数据库名称: $DB_NAME"
    log_info "数据库用户: $DB_USER"
    log_info "备份文件: $BACKUP_FILE"
    
    # 执行备份
    BACKUP_START_TIME=$(date +%s)
    
    # 使用 MYSQL_PWD 环境变量传递密码（避免命令行密码警告）
    if MYSQL_PWD="$DB_PASS" mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --quick \
        --lock-tables=false \
        "$DB_NAME" > "$BACKUP_FILE" 2>"$BACKUP_ERROR_LOG"; then
        
        # 检查备份文件大小
        BACKUP_FILE_SIZE=$(stat -c%s "$BACKUP_FILE" 2>/dev/null || echo "0")
        
        if [ "$BACKUP_FILE_SIZE" -eq 0 ]; then
            log_error "数据库备份失败！备份文件大小为 0"
            log_error "错误信息："
            cat "$BACKUP_ERROR_LOG" | tee -a "$BUILD_LOG"
            rm -f "$BACKUP_FILE" "$BACKUP_ERROR_LOG"
            return 1
        fi
        
        if [ "$BACKUP_FILE_SIZE" -lt 1024 ]; then
            log_warning "备份文件大小异常（小于 1KB），请检查："
            cat "$BACKUP_FILE" | head -20 | tee -a "$BUILD_LOG"
        fi
        
        BACKUP_END_TIME=$(date +%s)
        BACKUP_DURATION=$((BACKUP_END_TIME - BACKUP_START_TIME))
        
        # 压缩备份文件
        if command -v gzip &> /dev/null; then
            log_info "压缩备份文件..."
            if gzip -f "$BACKUP_FILE"; then
                BACKUP_FILE="${BACKUP_FILE}.gz"
                log_success "备份文件已压缩"
            else
                log_warning "压缩失败，保留未压缩的备份文件"
            fi
        fi
        
        BACKUP_SIZE=$(du -sh "$BACKUP_FILE" | cut -f1)
        
        log_success "数据库备份完成！"
        log_info "备份文件: $BACKUP_FILE"
        log_info "备份大小: $BACKUP_SIZE"
        log_info "备份耗时: ${BACKUP_DURATION} 秒"
        
        # 清理错误日志
        rm -f "$BACKUP_ERROR_LOG"
        
        # 只保留最新的备份文件，删除之前的备份
        log_info "清理旧的备份文件（只保留最新的）..."
        # 找到所有匹配的备份文件，按修改时间排序（最新的在前）
        OLD_BACKUPS=$(find "$BACKUP_DIR" -maxdepth 1 -name "db_backup_${DB_NAME}_*.sql*" -type f -printf '%T@ %p\n' 2>/dev/null | sort -rn | tail -n +2 | cut -d' ' -f2-)
        if [ -n "$OLD_BACKUPS" ]; then
            OLD_COUNT=$(echo "$OLD_BACKUPS" | wc -l)
            echo "$OLD_BACKUPS" | while read -r file; do
                if [ -f "$file" ]; then
                    rm -f "$file" 2>/dev/null && log_info "  已删除: $(basename "$file")" || true
                fi
            done
            log_success "已清理 $OLD_COUNT 个旧备份文件，保留最新的备份"
        else
            log_info "没有找到需要清理的旧备份文件"
        fi
        
        return 0
    else
        log_error "数据库备份失败！mysqldump 命令执行出错"
        log_error "错误信息："
        if [ -f "$BACKUP_ERROR_LOG" ]; then
            cat "$BACKUP_ERROR_LOG" | tee -a "$BUILD_LOG"
            rm -f "$BACKUP_ERROR_LOG"
        fi
        log_error "请检查："
        log_error "  1. 数据库连接信息是否正确（主机、端口、用户名、密码）"
        log_error "  2. 数据库用户是否有足够的权限"
        log_error "  3. 数据库服务是否正常运行"
        log_error "  4. 网络连接是否正常"
        rm -f "$BACKUP_FILE"
        return 1
    fi
}

# 主函数
main() {
    # 清空日志文件
    > "$BUILD_LOG"
    
    log_info "=========================================="
    log_info "开始前端编译更新流程"
    log_info "时间: $(date '+%Y-%m-%d %H:%M:%S')"
    log_info "=========================================="
    
    # 加载数据库配置
    load_env_config
    
    # 备份数据库（即使失败也继续执行）
    set +e  # 临时禁用错误退出，允许备份失败时继续执行
    backup_database
    BACKUP_RESULT=$?
    set -e  # 重新启用错误退出
    if [ $BACKUP_RESULT -ne 0 ]; then
        log_warning "数据库备份失败，继续执行构建流程..."
    fi
    
    # 检查和修复 storage 目录权限（即使失败也继续执行）
    set +e  # 临时禁用错误退出
    fix_storage_permissions
    PERM_RESULT=$?
    set -e  # 重新启用错误退出
    if [ $PERM_RESULT -ne 0 ]; then
        log_warning "storage 目录权限修复失败，继续执行构建流程..."
    fi
    
    # 清理临时文件（即使失败也继续执行）
    set +e  # 临时禁用错误退出
    cleanup_temp_files
    CLEANUP_RESULT=$?
    set -e  # 重新启用错误退出
    if [ $CLEANUP_RESULT -ne 0 ]; then
        log_warning "临时文件清理失败，继续执行构建流程..."
    fi
    
    # 检查必要的命令
    log_info "检查环境依赖..."
    check_command "node"
    check_command "npm"
    
    # 检查是否安装了 pnpm
    if command -v pnpm &> /dev/null; then
        PACKAGE_MANAGER="pnpm"
        log_info "检测到 pnpm，使用 pnpm 作为包管理器"
    else
        PACKAGE_MANAGER="npm"
        log_warning "未检测到 pnpm，使用 npm 作为包管理器"
    fi
    
    # 检查 web 目录是否存在
    if [ ! -d "$WEB_DIR" ]; then
        log_error "前端目录不存在: $WEB_DIR"
        exit 1
    fi
    
    # 进入 web 目录
    cd "$WEB_DIR"
    log_info "当前目录: $(pwd)"
    
    # 检查 package.json 是否存在
    if [ ! -f "package.json" ]; then
        log_error "package.json 不存在"
        exit 1
    fi
    
    # 检查 node_modules 是否存在，如果不存在则安装依赖
    if [ ! -d "node_modules" ]; then
        log_warning "node_modules 不存在，开始安装依赖..."
        log_info "执行: $PACKAGE_MANAGER install"
        if $PACKAGE_MANAGER install; then
            log_success "依赖安装完成"
        else
            log_error "依赖安装失败"
            exit 1
        fi
    else
        log_info "检测到 node_modules，检查关键依赖..."
        # 检查 vite 命令是否可用（优先检查 .bin 目录）
        VITE_FOUND=false
        if [ -f "node_modules/.bin/vite" ]; then
            VITE_FOUND=true
            log_info "找到 vite 命令: node_modules/.bin/vite"
        elif [ -f "node_modules/vite/bin/vite.js" ]; then
            # vite 包存在但符号链接可能缺失，需要重新安装或修复
            log_warning "vite 包存在但 .bin/vite 符号链接缺失，重新安装依赖以修复..."
            log_info "执行: $PACKAGE_MANAGER install"
            if $PACKAGE_MANAGER install; then
                log_success "依赖安装完成"
                VITE_FOUND=true
            else
                log_error "依赖安装失败"
                exit 1
            fi
        elif [ -d "node_modules/vite" ]; then
            # 只有目录但文件不存在，需要重新安装
            log_warning "vite 目录存在但文件不完整，重新安装依赖..."
            log_info "执行: $PACKAGE_MANAGER install"
            if $PACKAGE_MANAGER install; then
                log_success "依赖安装完成"
                VITE_FOUND=true
            else
                log_error "依赖安装失败"
                exit 1
            fi
        fi
        
        if [ "$VITE_FOUND" = false ]; then
            log_warning "vite 未找到，重新安装依赖..."
            log_info "执行: $PACKAGE_MANAGER install"
            if $PACKAGE_MANAGER install; then
                log_success "依赖安装完成"
            else
                log_error "依赖安装失败"
                exit 1
            fi
        else
            log_info "关键依赖检查通过"
        fi
    fi
    
    # 删除旧的构建目录（如果存在）
    if [ -d "dist" ]; then
        log_info "删除旧的构建目录..."
        rm -rf dist
    fi
    
    # 执行构建
    log_info "=========================================="
    log_info "开始构建前端项目..."
    log_info "执行: $PACKAGE_MANAGER run build"
    log_info "=========================================="
    
    # 确保 node_modules/.bin 在 PATH 中
    export PATH="${WEB_DIR}/node_modules/.bin:${PATH}"
    
    BUILD_START_TIME=$(date +%s)
    
    if $PACKAGE_MANAGER run build; then
        BUILD_END_TIME=$(date +%s)
        BUILD_DURATION=$((BUILD_END_TIME - BUILD_START_TIME))
        
        log_success "=========================================="
        log_success "前端构建完成！"
        log_success "构建耗时: ${BUILD_DURATION} 秒"
        log_success "=========================================="
        
        # 检查构建输出目录
        if [ -d "dist" ]; then
            DIST_SIZE=$(du -sh dist | cut -f1)
            log_info "构建输出目录: dist"
            log_info "构建产物大小: $DIST_SIZE"
            
            # 列出构建产物
            log_info "构建产物列表:"
            ls -lh dist/ | head -20 | tee -a "$BUILD_LOG"
            
            # 部署到生产目录
            log_info "=========================================="
            log_info "开始部署构建产物到生产目录..."
            log_info "=========================================="
            
            # 检查 public 目录是否存在
            if [ ! -d "$PUBLIC_DIR" ]; then
                log_error "生产目录不存在: $PUBLIC_DIR"
                exit 1
            fi
            
            # 复制构建产物到 public 目录
            log_info "复制构建产物到生产目录..."
            
            # 删除旧的 index.html 和 assets（如果存在）
            [ -f "${PUBLIC_DIR}/index.html" ] && rm -f "${PUBLIC_DIR}/index.html"
            [ -d "${PUBLIC_DIR}/assets" ] && rm -rf "${PUBLIC_DIR}/assets"
            
            # 复制新文件
            if cp -r dist/* "$PUBLIC_DIR/" 2>/dev/null; then
                # 设置文件权限
                chown -R www:www "$PUBLIC_DIR/index.html" "$PUBLIC_DIR/assets" 2>/dev/null || true
                chmod -R 755 "$PUBLIC_DIR/assets" 2>/dev/null || true
                
                log_success "构建产物已成功部署到生产目录！"
            else
                log_error "复制文件失败"
                exit 1
            fi
            
            
        else
            log_warning "未找到 dist 目录，请检查构建配置"
        fi
        
        log_success "前端编译更新完成！"
        log_info "构建日志已保存到: $BUILD_LOG"
        
    else
        log_error "=========================================="
        log_error "前端构建失败！"
        log_error "请查看错误信息并修复后重试"
        log_error "=========================================="
        
        exit 1
    fi
}

# 执行主函数
main "$@"

