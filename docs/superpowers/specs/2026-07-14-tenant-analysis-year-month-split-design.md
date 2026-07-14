# テナント管理 契約・解約分析 年別・月別分割リデザイン設計書

- 作成日: 2026-07-14
- 対象: テナント管理「契約・解約分析」（`/tenant/analysis`）
- 種別: 既存 read-only 集計画面の**表示ロジック刷新**（DB 変更なし・ルート/権限/サイドバー変更なし）
- 前身 spec: `docs/superpowers/specs/2026-07-13-tenant-contract-termination-analysis-design.md`（年×月マトリクス版。記録として残す）
- 承認済みモック: `docs/mockups/tenant/analysis/redesign-mock.html`（グラフのみ・年別最大10年・エメラルド配色）
- 先例 / 罠:
  - Chart.js 既存4画面（`zeal/dashboard`, `dashboard/_executive_charts`, `housing/_dashboard_chart`, `transactions/summary`）— CDN `cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js` を content 内 `<script src>` で読み込む方式を踏襲。いずれも**常時表示**の canvas に描画しており `x-show` 非表示タブへの Chart 生成の先例は無い（§4.4）。
  - Bug #1 / #19 / #23 / #26（`docs/RULES.md`）— アロー関数 x-data・未コンパイル Tailwind・`@json` 属性内 / 多行配列。
  - 新規論点: **Chart.js × Alpine `x-show`（初期非表示タブ）の描画崩れ** — 本 spec の実装で対策必須（§5.5 / §8）。

## 1. 背景・目的

現行の `/tenant/analysis` は「契約年 × 契約月のクロス集計マトリクス（1セル＝特定年かつ月の件数・ヒートマップ濃淡）」で契約分析・解約分析を提供している。しかし:

- マトリクスは年軸と月軸の**交差**で見るため、「年ごとの推移（トレンド）」と「月ごとの季節性」という**2つの独立した問い**が読み取りにくい。
- データが疎（低ボリューム）だとヒートマップ濃淡が意味を持ちにくい。

そこで、年軸と月軸を**別々の集計**に分割し、それぞれを棒グラフで直感的に見せる形へリデザインする。

達成すること（契約・解約の両タブで統一）:

1. **年別集計**: 各年の合計件数の推移。棒グラフ。**最大で直近10年分**を表示。
2. **月別集計**: 全年合算の月別件数（季節性）。1〜12月・1系列の棒グラフ。
3. 表示は**グラフのみ**（表・マトリクス・ヒートマップは全廃）。
4. 契約 / 解約は現行同様**1画面のタブ切替**（Alpine、リロードなし）。

## 2. 決定事項（確定）

前セッションの brainstorming でユーザー確認済み。

| # | 論点 | 決定 |
|---|---|---|
| D1 | 全体方針 | 年×月クロス集計マトリクスを**廃止** → 「年別集計」＋「月別集計」の2独立集計に分割 |
| D2 | 対象タブ | 「契約分析」「解約分析」の**両タブ**を同じ形に統一。タブ UI 自体は維持 |
| D3 | 年別集計の範囲 | 各年の合計件数の推移。**最大で直近10年分**（＝データのある年のうち新しい方から最大10年。10年未満なら有る分だけ。**空の年でパディングしない**） |
| D4 | 月別集計の範囲 | **全年合算**の季節性（1〜12月・1系列）。**全期間**を合算（現状踏襲。10年で切らない） |
| D5 | 表示形式 | **グラフのみ**（表は全廃）。棒グラフ（Chart.js）。各カード＝棒グラフ1本フル幅 |
| D6 | レイアウト | 各タブ内に「年別集計カード」→「月別集計カード」を縦積み |
| D7 | 総計バッジ | 各カードのスコープに合わせる（年別＝**表示中の年の計** ／ 月別＝**全期間計**。§下の注記参照） |
| D8 | 集計定義（現状踏襲） | 件数のみ（金額なし） / 契約＝**全ステータス**・`contract_date` 基準 / 解約＝**`terminated` のみ**・`contract_end_date` 基準 / department=tenant・SoftDelete 除外 / **暦年**（1〜12月） |
| D9 | ルート / 権限 / サイドバー | すべて**変更なし**。`/tenant/analysis`（`tenant.analysis.index`）・`department.access:tenant`・role 制限なし・read-only・**DB スキーマ変更なし** |

**D7 の総計一致条件（精密化）**: 年別バッジは「**表示中（＝新しい方から最大10年分）の年**」の合計。月別バッジは「**全期間**」の合計。両者が一致するのは **データの存在する年の種類数が 10 以下** のとき。11 種類以上あると、年別バッジ（新しい10年分のみ）＜ 月別バッジ（全期間）となる（意図的・§8）。モックのダミーは 2017〜2026 のちょうど10年分なので両者一致（89件=89件）。

## 3. スコープ

### 3.1 変更対象ファイル

| ファイル | 区分 | 変更内容 |
|---|---|---|
| `app/Services/Tenant/ContractAnalysisService.php` | 改修 | `build()` の戻り値を「年×月マトリクス」→「年別・月別の2集計」へ。`[year, month]` 抽出ロジック（契約＝全 status/contract_date、解約＝terminated/end_date、department=tenant、SoftDelete 除外）は流用 |
| `app/Http/Controllers/Tenant/AnalysisController.php` | 改修 | サービス生集計をビューへ渡す＋ Chart.js 用ペイロード（年ラベル文字列化・月ラベル「◯月」化）を組む |
| `resources/views/tenant/analysis/index.blade.php` | 改修 | タブ UI 維持。各タブに年別カード＋月別カードを縦積み。末尾で Chart.js CDN ＋初期化 script |
| `resources/views/tenant/analysis/_matrix.blade.php` | **廃止** | マトリクス表 partial を削除 |
| `resources/views/tenant/analysis/_charts.blade.php` | **新規** | 契約 / 解約共用。年別カード＋月別カード（canvas ＋総計バッジ＋空データ表示） |
| `tests/Feature/Tenant/ContractAnalysisTest.php` | 改修 | 新集計形状（年別カウント・最大10年・月別全年合算・年別/月別 total 不一致・空データ）へ全面改稿 |

### 3.2 変更しないファイル

- `routes/web.php`（`tenant.analysis.index` そのまま）
- `resources/views/layouts/partials/sidebar.blade.php`（「契約・解約分析」項目そのまま・2箇所とも）
- DB スキーマ / マイグレーション / raw SQL（一切なし）

### 3.3 スコープ外（今回やらないこと）

D8 の現状踏襲を除き、以下は対象外:

- 金額集計（賃料合計）。件数のみ。
- 物件別・担当者別・用途別フィルタ。
- CSV / Excel エクスポート。
- 会計期（5/1始まり・第XX期）ベースの集計軸。暦年のみ。
- グラフからのドリルダウン（棒クリックで該当年 / 月の契約一覧へ）。
- 他部署（Mansion / Housing / RealEstate / DAD）への横展開。

## 4. 現状の整理（検証済みのコード事実）

### 4.1 現行 `ContractAnalysisService::build()`

`contract_date` / `status` / `contract_end_date` の3列を `where('department', Tenant)->get()` し、PHP（Carbon）側で集計:

- 契約: `contract_date != null` の全行 → `[year, month]`（全ステータス）
- 解約: `status === Terminated && contract_end_date != null` → `[end_date year, end_date month]`
- これらを `matrix()` で `cells[year][month]` / `yearTotals` / `monthTotals` / `grandTotal` / `max` に集約。

→ **`[year, month]` ペアの抽出部分（契約＝全 status/contract_date、解約＝terminated/end_date、department=tenant、SoftDelete 除外）はそのまま流用**。集約先だけ「年別・月別の2本」に変える。`matrix()` は廃止。

### 4.2 集計は PHP（Carbon）側で行う（SQL 日付関数禁止）

Feature テストは SQLite in-memory。SQLite に MySQL の `YEAR()`/`MONTH()` は無い。現行同様、**日付行を取得して PHP 側で年月グルーピング**する（`selectRaw('YEAR(...)')` は使わない）。テナント契約は低ボリューム（現状10件）で PHP 集計で十分（§8 のボリューム留意点）。

### 4.3 Chart.js の既存読み込みパターン

- `layouts/app.blade.php` は `@yield('content')` 方式で**スクリプトスタック機構が無い**。各ビューは `@section('content')` 内で完結。
- 既存4画面はいずれも content 内で `<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>` を直書きし、続く `<script>` で `new Chart(...)`。`maintainAspectRatio: false` 使用。
- → 本画面も index.blade.php の content 末尾で同じ CDN を読み込み、初期化 script を置く。

### 4.4 Chart.js × 非表示タブ の描画崩れ（新規論点・最重要）

- 既存4画面はいずれも**常時表示**の canvas に描画しており、`x-show`（`display:none`）で初期非表示のパネルに Chart を生成する**先例が無い**。
- 本画面は解約タブが初期非表示（`x-show`=false）。**`display:none` の要素は幅0**のため、その状態で `new Chart()` すると棒が幅0基準でレイアウトされ、表示に切り替えても左に寄る等の崩れが出る。
- → §5.5 の「遅延初期化 ＋ 表示後リフロー ＋ onResize」で対策。

## 5. 実装設計

### 5.1 集計サービス `ContractAnalysisService`（改修）

`build()` は契約・解約それぞれ `byYear` / `byMonth` を返す。`MAX_YEARS` はクラス冒頭で定数化（マジックナンバー回避）。

```php
class ContractAnalysisService
{
    /** 年別集計で表示する最大年数（新しい方から） */
    private const MAX_YEARS = 10;

    /**
     * テナント契約の「契約」「解約」を 年別（最大10年）／月別（全年合算）で集計する。
     * @return array{contract: array, termination: array}
     */
    public function build(): array
    {
        $rows = Contract::query()
            ->where('department', DepartmentCode::Tenant)
            ->get(['contract_date', 'status', 'contract_end_date']);

        // 契約: 全ステータス・contract_date 基準（D8）
        $contractDates = $rows
            ->filter(fn (Contract $c) => $c->contract_date !== null)
            ->map(fn (Contract $c) => [(int) $c->contract_date->year, (int) $c->contract_date->month]);

        // 解約: terminated のみ・contract_end_date 基準（D8）
        $terminationDates = $rows
            ->filter(fn (Contract $c) => $c->status === ContractStatus::Terminated && $c->contract_end_date !== null)
            ->map(fn (Contract $c) => [(int) $c->contract_end_date->year, (int) $c->contract_end_date->month]);

        return [
            'contract'    => $this->summarize($contractDates),
            'termination' => $this->summarize($terminationDates),
        ];
    }

    /**
     * [year, month] ペアから年別（最大10年）・月別（全年合算）を組み立てる。
     * @param  Collection<int, array{0:int,1:int}>  $pairs
     * @return array{byYear: array, byMonth: array}
     */
    private function summarize(Collection $pairs): array
    {
        return [
            'byYear'  => $this->byYear($pairs->map(fn (array $p) => $p[0])),
            'byMonth' => $this->byMonth($pairs->map(fn (array $p) => $p[1])),
        ];
    }

    /**
     * 年別: データのある年のうち新しい方から最大10年。空年でパディングしない。
     * グラフ表示のため昇順（古い→新しい）で返す。total は「表示中の年」の計（D7）。
     * @param  Collection<int, int>  $years
     * @return array{labels: list<int>, values: list<int>, total: int}
     */
    private function byYear(Collection $years): array
    {
        $counts = [];
        foreach ($years as $y) {
            $counts[$y] = ($counts[$y] ?? 0) + 1;
        }
        krsort($counts);                                          // 年 降順
        $counts = array_slice($counts, 0, self::MAX_YEARS, true); // 新しい方から最大10年（キー保持）
        ksort($counts);                                           // グラフ用 昇順

        return [
            'labels' => array_map('intval', array_keys($counts)), // [2017, 2018, ...]
            'values' => array_values($counts),                    // labels と index 対応
            'total'  => array_sum($counts),                       // 表示中の年の計（直近10年分）
        ];
    }

    /**
     * 月別: 全年合算の 1〜12月。total は全期間計（D7）。
     * @param  Collection<int, int>  $months
     * @return array{labels: list<int>, values: list<int>, total: int}
     */
    private function byMonth(Collection $months): array
    {
        $counts = array_fill(1, 12, 0);
        foreach ($months as $m) {
            $counts[$m]++;
        }

        return [
            'labels' => range(1, 12),          // [1..12]
            'values' => array_values($counts), // index0=1月 … index11=12月（labels と index 対応）
            'total'  => array_sum($counts),    // 全期間計
        ];
    }
}
```

**要点**:

- `byYear.total`（表示中の年の計）と `byMonth.total`（全期間計）は、データの存在する年が11種類以上あると一致しない（D7・意図的）。11年以上前の件数は年別 total から落ちるが月別 total には残る。
- `byYear.labels` / `byYear.values`、`byMonth.labels` / `byMonth.values` はいずれも **index 対応の順序付きリスト**（Chart.js の `labels[]` / `data[]` にそのまま渡せる形）。`byMonth.values` は **index0 が1月・index11 が12月**。
- 空データ時: `byYear.labels=[]` / `byYear.values=[]` / `byYear.total=0`、`byMonth.values=[0×12]` / `byMonth.total=0`。ゼロ除算なし。
- SQL 日付関数を使わない（4.2）。戻り値は純配列（`@json`／`Js::from` に渡しても安全）。

**「空年でパディングしない」の確定挙動**（D3）: 例えばデータが 2018・2020・2024・2025 の4種類なら `labels = [2018, 2020, 2024, 2025]`（間の 2019・2021〜2023 は**入れない**）。→ §8 に視覚上の留意点。

### 5.2 コントローラ `Tenant\AnalysisController`（改修）

サービス生集計をビューへ渡しつつ、Chart.js 用ペイロード（年ラベルは文字列・月ラベルは「◯月」）を組む。

```php
public function index(ContractAnalysisService $service): View
{
    $data = $service->build();

    return view('tenant.analysis.index', [
        'contract'    => $data['contract'],       // 総計バッジ・空判定に使う生集計
        'termination' => $data['termination'],
        'charts'      => [                          // Chart.js 用（Js::from で埋め込む）
            'contract'    => $this->chartPayload($data['contract']),
            'termination' => $this->chartPayload($data['termination']),
        ],
    ]);
}

/** サービス集計を Chart.js の {labels, values} 形へ整形 */
private function chartPayload(array $summary): array
{
    return [
        'year'  => [
            'labels' => array_map(fn (int $y) => (string) $y, $summary['byYear']['labels']),
            'values' => $summary['byYear']['values'],
        ],
        'month' => [
            'labels' => array_map(fn (int $m) => $m . '月', $summary['byMonth']['labels']),
            'values' => $summary['byMonth']['values'],
        ],
    ];
}
```

- 総計バッジ・空データ判定はビューで `$contract['byYear']['total']` 等を直接使う。
- Chart.js に渡すのは `$charts` のみ（`Js::from` で埋め込み・§5.5）。

### 5.3 ビュー `tenant/analysis/index.blade.php`（改修）

タブ UI・breadcrumb・title は現行を維持。中身を `_matrix` include から `_charts` include へ差し替え、末尾に Chart.js CDN ＋初期化 script。

Alpine は**名前付き関数** `x-data="tenantAnalysis()"`（Bug #1 のアロー関数回避）。Chart データは `x-data` 属性に入れず（Bug #23/#26）、末尾 script で `Js::from` → グローバル定数 → 関数が参照。

```blade
@section('content')
<div x-data="tenantAnalysis()" x-init="init()">

    {{-- ページヘッダー --}}
    <div class="mb-5">
        <h1 class="text-lg font-bold text-gray-900">契約・解約分析</h1>
        <p class="text-sm text-gray-500" style="margin-top:4px;">契約年ごとの件数（最大直近10年の推移）と、契約月ごとの件数（全年合算の季節性）を、それぞれ棒グラフで表示します。</p>
    </div>

    {{-- タブ --}}
    <div class="flex gap-1 mb-4" role="tablist">
        <button type="button" @click="show('contract')"
                :class="tab === 'contract' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                class="px-4 py-2 rounded-md text-sm font-semibold transition-colors">契約分析</button>
        <button type="button" @click="show('termination')"
                :class="tab === 'termination' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                class="px-4 py-2 rounded-md text-sm font-semibold transition-colors">解約分析</button>
    </div>

    {{-- 契約パネル --}}
    <div x-show="tab === 'contract'" x-cloak>
        @include('tenant.analysis._charts', ['prefix' => 'contract', 'summary' => $contract, 'noun' => '契約'])
    </div>

    {{-- 解約パネル --}}
    <div x-show="tab === 'termination'" x-cloak>
        @include('tenant.analysis._charts', ['prefix' => 'termination', 'summary' => $termination, 'noun' => '解約'])
    </div>

</div>

{{-- Chart.js（cdn.jsdelivr.net のみ許可・§5.5） --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    // ... §5.5 の tenantAnalysis() 定義 ...
</script>
@endsection
```

- `x-cloak` の CSS は `app.css` に定義済み（前身 spec §3.5）→ タブ初期表示のちらつき防止に流用。

### 5.4 パーシャル `tenant/analysis/_charts.blade.php`（新規・契約 / 解約共用）

`$prefix`（canvas id 用: `contract` / `termination`）・`$summary`（サービス生集計）・`$noun`（「契約」/「解約」）を受け取り、年別カード → 月別カードを縦積み。canvas は**固定 height + width:100% の inline style**（Bug #19）。総計バッジ・空データ表示は Blade。

```blade
@php
    $yearTotal  = $summary['byYear']['total'];
    $monthTotal = $summary['byMonth']['total'];
@endphp

{{-- 年別集計カード --}}
<div class="bg-white border border-gray-200 rounded-lg" style="padding:16px 18px; margin-bottom:16px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="width:8px; height:16px; border-radius:3px; background:#059669; display:inline-block;"></span>
            <span style="font-size:14px; font-weight:700; color:#111827;">年別集計</span>
            <span style="font-size:12px; color:#9CA3AF; font-weight:500;">{{ $noun }}年ごとの合計件数（最大直近10年）</span>
        </div>
        <span style="font-size:12px; font-weight:700; color:#047857; background:#ECFDF5; border:1px solid #A7F3D0; border-radius:999px; padding:3px 12px; white-space:nowrap;">総計 {{ number_format($yearTotal) }}件</span>
    </div>
    @if($yearTotal === 0)
        <div style="padding:40px; text-align:center; color:#9CA3AF; font-size:14px;">{{ $noun }}データがありません</div>
    @else
        <div style="width:100%; height:300px; position:relative;"><canvas id="chart-{{ $prefix }}-year"></canvas></div>
    @endif
</div>

{{-- 月別集計カード --}}
<div class="bg-white border border-gray-200 rounded-lg" style="padding:16px 18px; margin-bottom:16px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="width:8px; height:16px; border-radius:3px; background:#059669; display:inline-block;"></span>
            <span style="font-size:14px; font-weight:700; color:#111827;">月別集計</span>
            <span style="font-size:12px; color:#9CA3AF; font-weight:500;">{{ $noun }}月ごとの合計件数（全年合算）</span>
        </div>
        <span style="font-size:12px; font-weight:700; color:#047857; background:#ECFDF5; border:1px solid #A7F3D0; border-radius:999px; padding:3px 12px; white-space:nowrap;">総計 {{ number_format($monthTotal) }}件</span>
    </div>
    @if($monthTotal === 0)
        <div style="padding:40px; text-align:center; color:#9CA3AF; font-size:14px;">{{ $noun }}データがありません</div>
    @else
        <div style="width:100%; height:300px; position:relative;"><canvas id="chart-{{ $prefix }}-month"></canvas></div>
    @endif
</div>
```

**要点**:

- カード枠・バッジ配色はモック（承認済み）準拠。総計色は粗利色 `#047857`（Conventions）。
- 空データ時は canvas を出さず（＝Chart 初期化もスキップ）メッセージのみ。§5.5 の `bar()` は canvas 不在で null を返し安全にスキップ。
- 年別が空 ⟺ 全体が空 ⟺ 月別も空（データが1件でもあればその年が `byYear.labels` に入るため、「片方だけ空」は起きない）。
- `number_format` で3桁区切り（将来件数増でも可読）。テキストコントラストは AA を満たす暗色（`#111827` / `#374151` / `#047857`）を使用（白文字を濃色背景に載せない）。

### 5.5 Chart.js 初期化 script（index.blade.php 末尾）

`x-data` の名前付き関数 `tenantAnalysis()` に Chart 管理を集約。**遅延初期化（表示後）＋ 表示後リフロー ＋ onResize** で非表示タブ描画崩れを回避（4.4）。モックの実装をそのまま本番ルール化する。

```html
<script>
    const TENANT_ANALYSIS_CHARTS = {{ \Illuminate\Support\Js::from($charts) }};

    function tenantAnalysis() {
        return {
            tab: 'contract',
            built: { contract: false, termination: false },
            charts: {},

            init() {
                // 初期タブ（契約）はレイアウト確定後に描画（幅0回避）
                this.$nextTick(() => this.render('contract'));
            },

            show(which) {
                this.tab = which;
                // display:none → 表示に切り替わった後に描画 / リフロー
                this.$nextTick(() => this.render(which));
            },

            render(which) {
                if (this.built[which]) {
                    // 既存チャートは表示時にリフロー（幅を再計算して棒を再配置）
                    (this.charts[which] || []).forEach(c => { c.resize(); c.update('none'); });
                    return;
                }
                this.built[which] = true;
                const data = TENANT_ANALYSIS_CHARTS[which];
                this.charts[which] = [
                    this.bar('chart-' + which + '-year', data.year),
                    this.bar('chart-' + which + '-month', data.month),
                ].filter(Boolean); // 空データ（canvas 無し）は null → 除外
            },

            bar(canvasId, ds) {
                const el = document.getElementById(canvasId);
                if (!el) return null;
                return new Chart(el, {
                    type: 'bar',
                    data: { labels: ds.labels, datasets: [{
                        data: ds.values,
                        backgroundColor: 'rgba(5,150,105,0.82)',
                        hoverBackgroundColor: '#059669',
                        borderRadius: 4, maxBarThickness: 48,
                    }]},
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        // 非表示→表示やコンテナ幅変化のたびに棒を再配置（左寄り防止）
                        onResize: (chart) => requestAnimationFrame(() => chart.update('none')),
                        plugins: { legend: { display: false }, tooltip: { displayColors: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { precision: 0, color: '#9CA3AF', font: { size: 11 } }, grid: { color: '#F3F4F6' } },
                            x: { ticks: { color: '#6B7280', font: { size: 11 } }, grid: { display: false } },
                        },
                    },
                });
            },
        };
    }
</script>
```

**設計の肝**:

- `built` フラグで各タブ**初回表示時のみ** Chart 生成。以降の再表示は `resize() + update('none')` でリフロー。
- 初期タブ（契約）は `x-init="init()"` → `$nextTick` でレイアウト確定後に描画。
- 解約タブは初回 `show('termination')` 時に描画（それまで `display:none` なので生成しない＝幅0回避）。
- `onResize` フックは canvas リサイズ後に棒を再配置する保険（rAF で observer コールバックの再入を防ぐ）。
- Chart データは `Js::from`（`JSON.parse('...')` 形式＝属性 / クォート安全）で埋め込み、`x-data` には入れない（Bug #23/#26）。`x-data="tenantAnalysis()"` は名前付き関数（Bug #1）。
- `bar()` は canvas 不在（空データ）で `null` を返し `.filter(Boolean)` で除外。

## 6. テスト計画（`ContractAnalysisTest.php` 改修・RefreshDatabase）

現行の `makeContract`（物件＋区画＋顧客＋契約を1セット `create()`）と `executive()` ヘルパは流用。集計形状が変わるため**旧 T1〜T8 のアサーションは全面改稿**する（メソッド単位で作り直す）。

> `byMonth.values` は **index0=1月 … index11=12月** のリスト。テストで月番号アクセスしたい箇所は `$r['byMonth']['values'][7]`（8月）のように index を用いる（または `array_combine(range(1,12), $r['byMonth']['values'])[8]`）。

| # | 検証 | 主眼 |
|---|---|---|
| T1 | **年別カウント・昇順**: 2024×2件・2025×1件 → `byYear.labels=[2024,2025]`（昇順）・`values=[2,1]`・`total=3` | 年別集計 |
| T2 | **月別 全年合算**: 2024/8・2025/8・2024/3 → `byMonth.values[7]`（8月）=2・`values[2]`（3月）=1・`total=3`（異なる年の同月が合算される） | 月別合算 |
| T3 | **最大10年 ＋ total 不一致**: 11 種類の年（各1件、例 2015〜2025）を作成 → `byYear.labels` は新しい10年（2016〜2025）・**2015が落ちる**・`byYear.total=10` / `byMonth.total=11`（全期間） | **D3/D7 の核心** |
| T4 | **契約=contract_date / 解約=end_date**: 2024/8 契約 → 2025/3 解約（terminated） → 契約 `byYear.labels=[2024]`・`byMonth.values[7]`（8月）=1、解約 `byYear.labels=[2025]`・`byMonth.values[2]`（3月）=1 | D8 基準 |
| T5 | **active は解約に出ない**: active＋end_date あり → contract 側 `byYear.total=1`・termination 側 `byYear.total=0`／`byMonth.total=0` | D8 |
| T6 | **terminated＋end_date=null**: 契約 `total=1`・解約 `total=0`（end_date 無く解約に出ない） | 異常データガード |
| T7 | **部署フィルタ**: department=mansion → 契約・解約とも `total=0` | tenant 限定 |
| T8 | **SoftDelete 除外**: 論理削除契約 → 契約・解約とも `total=0` | グローバルスコープ |
| T9 | **空データ**: 0件 → `byYear.labels=[]`・`byYear.values=[]`・`byYear.total=0`・`byMonth.values=[0,0,0,0,0,0,0,0,0,0,0,0]`・`byMonth.total=0` | ゼロ状態 |
| T10 | **ルート200・カード描画**: executive で `GET /tenant/analysis` → 200、`契約分析`・`解約分析`・`年別集計`・`月別集計` を表示 | HTTP |

> T1〜T9 は `ContractAnalysisService::build()` を直接呼ぶ Feature テスト（DB シードのみ・HTTP 不要）。T10 のみ HTTP。
> **T3 が本リデザイン固有の最重要ケース**（最大10年制限と年別/月別 total 不一致）。

## 7. デプロイ・検証手順（本番反映）

前身 spec と同じ流れ。**DB 変更・ルート変更・新規 PHP クラスとも なし**（既存クラスの改修のみ）。

1. worktree（`.claude/worktrees/tenant-analysis-split` 等）で実装。worktree に vendor 無し → `composer install`（dev込み）＋テスト用ダミー鍵 `.env` を用意して phpunit 実行（memory: `project_test_env_worktree_vendor`）。
2. `/commit`（論理単位で分割: サービス / コントローラ / ビュー〔index 改修＋新 partial ＋旧 `_matrix` 削除〕 / テスト）。
3. main repo（`/Users/masanori/site/manage`）で `git checkout 13.x && git merge --ff-only <branch>`。
4. **新規 PHP クラスなし** → `composer dump-autoload` 不要（既存 `AnalysisController` / `ContractAnalysisService` の中身改修のみ）。
5. **DB 変更なし** → SQL 実行不要。
6. **Blade 検証**（習慣・Bug #26 型対策）: コンパイル済みビューを lint。
   ```
   php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
   ```
7. **実データ検証**（認証必須画面・memory: `project_local_verify_env_and_technique`）: ローカル実 DB でレンダリング → **特に解約タブ（初期非表示）に切り替えた時に棒グラフが崩れず描画されるか**を実ブラウザ（Playwright / 手動）で確認（§5.5 の要件）。初期表示（契約タブ）・タブ往復・空データ状態も確認。埋め込みプレビューペインは rAF スロットリングで初回描画が崩れることがあるため、**実ブラウザで確認する**（§8）。
8. `./deploy.sh`（rsync ＋ 本番 `config:cache && route:cache && view:cache`）。**要ユーザー明示承認**（memory: `project_deploy_needs_explicit_user_authorization`）。
9. origin/13.x への push はユーザー明示指示時のみ。

## 8. リスク・留意点

- **Chart.js × 非表示タブ（最重要）**: §5.5 の遅延初期化＋リフロー＋onResize を必ず実装。省くと解約タブの棒が左寄り / 幅0になる。埋め込みプレビューペインはバックグラウンドで rAF/タイマーがスロットリングされ初回描画が崩れることがあるが、これは**ペイン固有現象**で実ブラウザの初期表示タブでは通常出ない。ただし**非表示 x-show タブは本番実ブラウザでも崩れうる**ため対策は必須。検証は実ブラウザで行う。
- **「空年でパディングしない」の視覚的注意**（D3）: データが飛び飛びの年（例 2018・2020・2024）だと、棒グラフでは等間隔に並び「連続した年」に見える恐れがある。ユーザー確定要件のためこの仕様で実装する。実データ（概ね連続する近年）では実害は小さい見込み。将来「連続年で0埋め表示」への切替が必要になれば `byYear` に range 補完オプションを足す（現時点では YAGNI）。
- **年別 total ≠ 月別 total**（D7）: データの存在する年が11種類以上のとき意図的に不一致（年別＝新しい10年分 ／ 月別＝全期間）。各カードのバッジは説明文（「最大直近10年」「全年合算」）とセットで誤解を減らすが、レビュー時に「バグではなく仕様」と認識すること。テスト T3 が担保。
- **SQLite / MySQL 日付関数差**: 集計は PHP（Carbon）。`selectRaw('YEAR/MONTH')` を混入させない（4.2）。レビュー時 `selectRaw` の有無を確認。
- **ボリューム**: 全 tenant 契約を `->get()` して PHP 集計（現状10件で問題なし）。将来数千件規模になれば DB 集計（MySQL `YEAR/MONTH` ＋ SQLite 分岐 or `strftime`）へ移行を検討（現時点では YAGNI）。
- **Bug #1 / #19 / #23 / #26**: x-data は名前付き関数 `tenantAnalysis()`、Chart データは `Js::from`、canvas コンテナ・配色・カード枠は inline style、`@json` 多行配列を使わない。タブボタンの Tailwind クラスは確認済みクラス（`bg-emerald-600` / `text-white` / `border` / `border-gray-300` / `rounded-md` / `px-4` / `py-2` / `text-sm` / `font-semibold` / `flex` / `gap-1` / `mb-4` / `mb-5`）のみ。
- **enum キャスト属性の tryFrom 禁止**（Bug #22）: 解約判定は `status === ContractStatus::Terminated`（比較）で行い `tryFrom` を使わない。維持すること。
- **旧 partial 削除**: `_matrix.blade.php` を削除する前に、include 元が index.blade.php のみであること（`grep -rn "analysis._matrix\|analysis/_matrix" resources/views/`）を確認。view:cache 再生成でコンパイル済み残骸が消える。
- **外部 CDN の SRI（Subresource Integrity）**: 本 spec の Chart.js CDN は SRI（`integrity="sha384-..."`）を付けていない。理由は既存 Chart.js 4画面（`zeal/dashboard` ほか）がいずれも SRI 未付与で、この1画面だけ付けると横並びを崩すため。SRI を導入するなら本画面単独ではなく、全 CDN 読み込み（Chart.js / SheetJS 等）を横断で対応する別タスクとする（本 spec のスコープ外）。
