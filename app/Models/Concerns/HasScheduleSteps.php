<?php

namespace App\Models\Concerns;

use App\Models\ScheduleStep;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * 工程表を持てる親（設計書 §3.3）。仕入れ案件 / 分譲地PJ / 建売物件 / 注文住宅 が use する。
 *
 * ⚠ **親ごとの差は abstract メソッドで吸収する。既定実装を置かない。**
 *   コード列・名称列の名前は親ごとに違う（procurement_code / project_code /
 *   property_code / order_code）。既定値を置くと、新しい親を足した人が override を
 *   忘れた瞬間に**無音で空欄**になる。abstract なら PHP が Fatal で止める。
 *
 * ⚠ **ボードと共通 partial は親の実クラスを知らないまま動く。** 直に $model->name と
 *   書かないこと。
 */
trait HasScheduleSteps
{
    /**
     * ⚠ 並び順は sort_order → id。id を第 2 キーに入れないと、
     *   sort_order が同値のとき DB 依存の順序になり画面がちらつく。
     */
    public function scheduleSteps(): MorphMany
    {
        return $this->morphMany(ScheduleStep::class, 'schedulable')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** 画面に出す案件コード */
    abstract public function scheduleCode(): string;

    /** 画面に出す案件名 */
    abstract public function scheduleName(): string;

    /**
     * ルート名の接頭辞（例: `realestate.procurements`）。
     *
     * ⚠ **親を足すときに触るのはここだけ**にするための 1 本。詳細 URL も部署も工程ルートも
     *   すべてこの接頭辞から導く。サービス側に「クラス => 接頭辞」の対応表を持つと、
     *   親を足した人が表を更新し忘れて `Route [.schedule-steps.store] not defined` になる。
     */
    abstract public function scheduleRoutePrefix(): string;

    /**
     * 実績（`actual_start` / `actual_end`）を扱うか（設計書 §3.1 D1）。
     *
     * `false` のとき:
     *   - 編集表に実績の 2 列を出さない
     *   - 保存経路が `actual_*` を受け付けず、`ScheduleStep` の saving フックが null に正規化する
     *   - 遅延を判定しない（工程の状態は日付だけで決まる。設計書 §4.1）
     *
     * ⚠ **既定実装を置かない**（この trait 冒頭の規約）。既定値を置くと、新しい親を足した人が
     *   override を忘れた瞬間に**無音で片方の挙動へ倒れる**。abstract なら PHP が Fatal で止める。
     *
     * ⚠ **共有部品（サービス・partial・コントローラ）は `instanceof` を書かず、必ずここに聞く。**
     */
    abstract public function scheduleTracksActuals(): bool;

    /** 詳細ページの URL */
    public function scheduleUrl(): string
    {
        return route($this->scheduleRoutePrefix() . '.show', $this);
    }

    /**
     * 'realestate' | 'housing'
     *
     * ⚠ 接頭辞の先頭がそのまま部署コードになっている（`realestate.procurements` → `realestate`）。
     *   ボードの絞り込みと `department.access` の引数がこれと一致している必要がある。
     */
    public function scheduleDepartment(): string
    {
        return explode('.', $this->scheduleRoutePrefix())[0];
    }

    /** 工程 CRUD のルート名（`store` / `reorder` / `update` / `destroy`） */
    public function scheduleStepRoute(string $action): string
    {
        return $this->scheduleRoutePrefix() . '.schedule-steps.' . $action;
    }

    /**
     * 既存の日付列から描く ◆（設計書 §3.4）。工程行としては作らない。
     *
     * ⚠ **読み取り専用。** 工程表の入力欄からは触れない。動かしたければ親の編集画面で直す。
     * ⚠ **「完成」は 1 つだけ。** scheduled と actual は同じ節目なので ◆ を 2 つ描かない。
     *
     * @return list<array{label: string, date: \Carbon\CarbonInterface}>
     */
    abstract public function autoMilestones(): array;
}
