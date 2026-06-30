#!/bin/bash
# deploy.sh - さくらサーバーへのデプロイスクリプト

SERVER="mitsuwa-ud@www3586.sakura.ne.jp"
APP_PATH="~/apps/manage"
WEB_PATH="~/www/mitsuwa-t/system/manage"

echo "=== [1/4] アプリケーション転送 ==="
rsync -avz \
  --exclude='.env' \
  --exclude='node_modules' \
  --exclude='.git' \
  --exclude='.gitignore' \
  --exclude='tests' \
  --exclude='docs' \
  --exclude='CLAUDE.md' \
  --exclude='.claude' \
  --exclude='.cursor' \
  --exclude='.vscode' \
  --exclude='.idea' \
  --exclude='.DS_Store' \
  --exclude='CLAUDE.md' \
  --exclude='README.md' \
  --exclude='CHANGELOG.md' \
  --exclude='README*' \
  --exclude='phpunit.xml' \
  --exclude='*.log' \
  --exclude='.playwright-mcp' \
  --exclude='prod-login.png' \
  --exclude='*.png' \
  --exclude='deploy.sh' \
  ./ ${SERVER}:${APP_PATH}/

echo "=== [2/4] アセット転送 ==="
rsync -avz \
  --exclude='index.php' \
  --exclude='.htaccess' \
  --exclude='.DS_Store' \
  ./public/ ${SERVER}:${WEB_PATH}/

echo "=== [3/4] キャッシュ更新 ==="
ssh ${SERVER} "cd ${APP_PATH} && \
  /usr/local/php/8.3/bin/php artisan config:cache && \
  /usr/local/php/8.3/bin/php artisan route:cache && \
  /usr/local/php/8.3/bin/php artisan view:cache"

echo "=== [4/4] 完了 ==="
echo "https://www.mitsuwat.co.jp/system/manage にアクセスして確認してください"
