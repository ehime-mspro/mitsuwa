#!/bin/bash
# deploy.sh - さくらサーバーへのデプロイスクリプト

SERVER="mitsuwa-ud@www3586.sakura.ne.jp"
APP_PATH="~/apps/manage"
WEB_PATH="~/www/mitsuwa-t/system/manage"

# public/build は git 管理外のビルド成果物。ここでビルドしないと Blade に足した
# Tailwind クラスが CSS に入らず無音で効かない（2026-04-23〜07-15 に 3 ヶ月凍結した）。
# 転送より先に実行し、失敗したら本番へは何も送らずに中断する。
echo "=== [1/6] フロントエンドビルド ==="
if [ ! -f package.json ]; then
  echo "ERROR: package.json が見つかりません。リポジトリのルートで実行してください。" >&2
  exit 1
fi
if [ ! -d node_modules ]; then
  echo "ERROR: node_modules がありません。'npm ci' を実行してから再度デプロイしてください。" >&2
  exit 1
fi
if ! npm run build; then
  echo "" >&2
  echo "ERROR: ビルドに失敗しました。本番へは何も転送していません。" >&2
  exit 1
fi
if [ ! -f public/build/manifest.json ]; then
  echo "" >&2
  echo "ERROR: public/build/manifest.json が生成されていません。本番へは何も転送していません。" >&2
  exit 1
fi

echo "=== [2/6] アプリケーション転送 ==="
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

echo "=== [3/6] アセット転送 ==="
rsync -avz \
  --exclude='index.php' \
  --exclude='.htaccess' \
  --exclude='.DS_Store' \
  ./public/ ${SERVER}:${WEB_PATH}/

# CSS/JS を変更するとハッシュ名が変わるため、旧バンドルが本番に孤児として残る
# （manifest.json は新を指すので無害だが、デプロイの度に蓄積する）。
# build/ は Vite の出力しか入らないディレクトリなので --delete して良い。
# ⚠ public/ 全体に --delete を付けるのは【厳禁】。public/storage は
#   storage/app/public への symlink で、本番のアップロード物を消しうる。
# 転送先が 2 つあるのは [2/6] が APP_PATH（Laravel が manifest を読む側）、
# [3/6] が WEB_PATH（ブラウザが実ファイルを取りに行く側）に配るため。
echo "=== [4/6] 旧バンドルの掃除 ==="
rsync -avz --delete ./public/build/ ${SERVER}:${WEB_PATH}/build/
rsync -avz --delete ./public/build/ ${SERVER}:${APP_PATH}/public/build/

echo "=== [5/6] キャッシュ更新 ==="
ssh ${SERVER} "cd ${APP_PATH} && \
  /usr/local/php/8.3/bin/php artisan config:cache && \
  /usr/local/php/8.3/bin/php artisan route:cache && \
  /usr/local/php/8.3/bin/php artisan view:cache"

echo "=== [6/6] 完了 ==="
echo "https://www.mitsuwat.co.jp/system/manage にアクセスして確認してください"
