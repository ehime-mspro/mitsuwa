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

# storage は本番が自分で育てる場所（添付・キャッシュ・記録）。手元から送ると本番の添付を
# 上書きしうる。上書きすると「同じパス・同じ大きさなら送り直さない」判定のバックアップが
# 変更を拾わず、控えと本番が静かに食い違う（2026-09-16 に除外）。
# 先頭の / は転送の一番上だけを指す指定。付けないと public/storage
# （storage/app/public への symlink）まで巻き添えになる。
# ※ 本番をゼロから作り直すときの storage のフォルダ作成は初期構築の仕事で、ここではやらない。
# png も先頭スラッシュ付き。手元のスクリーンショットは .gitignore の /*.png と同じく
# リポジトリの一番上に置く決まりで、public/images/ のロゴは本番に要る。
# bootstrap/cache も本番が自分で育てる場所。手元で config:cache を打つと、手元の .env を写した
# config.php（接続情報・暗号化キー入り）ができ、それが本番の config.php を上書きしてしまう。
# [5/6] が本番の .env から作り直すまでの間、本番が手元の設定で動く（[5/6] が失敗すれば残る）。
# 部品の名簿（packages.php・services.php）も送らない。本番で毎回作り直すのは [5/6]（理由はそこに書く）。
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
  --exclude='/storage/' \
  --exclude='/bootstrap/cache/' \
  --exclude='.playwright-mcp' \
  --exclude='prod-login.png' \
  --exclude='/*.png' \
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
# umask 077: 本番の .env と、それを写した bootstrap/cache/config.php には秘密（S3 の鍵・暗号化キー・
# メールのパスワード）が入るため、作り直すキャッシュも本人だけ読める 600 にする（PHP は本人の権限で動く）。
# 部品の名簿（packages.php・services.php）は、キャッシュを作る前に毎回消して作り直す（2026-09-25）。
# packages.php は Laravel が「無いときだけ」作る（PackageManifest::getManifest()）ので、[2/6] で送らない
# ままだと古いまま固まる。名簿に載った部品が vendor から消えると、画面も artisan も起動の時点で
# 止まり、package:discover 自身も同じところで止まる（手元で実測）。だから作り直す前に消す。
# services.php は部品の一覧が変わると自分で作り直すが、揃えて消す。消したあとは次の起動が作り直す
# （一時ファイルからの rename で置き換えるので、作りかけを読まれることはない。umask 077 なので 700）。
# ⚠ [2/6] で vendor を送ってから、ここで作り直すまでの数秒は古い名簿のまま動く。
ssh ${SERVER} "umask 077 && cd ${APP_PATH} && \
  rm -f bootstrap/cache/packages.php bootstrap/cache/services.php && \
  /usr/local/php/8.3/bin/php artisan package:discover && \
  /usr/local/php/8.3/bin/php artisan config:cache && \
  /usr/local/php/8.3/bin/php artisan route:cache && \
  /usr/local/php/8.3/bin/php artisan view:cache"

echo "=== [6/6] 完了 ==="
echo "https://www.mitsuwat.co.jp/system/manage にアクセスして確認してください"
