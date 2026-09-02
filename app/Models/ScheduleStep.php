<?php

namespace App\Models;

use App\Enums\ScheduleStepCategory;
use App\Support\ScheduleStepStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * 工程表の 1 行（設計書 §3）。4 親（仕入れ案件 / 分譲地PJ / 建売物件 / 注文住宅）に
 * ポリモーフィックにぶら下がる。
 *
 * ⚠ **行の種別（棒かマイルストーンか）に専用フラグを持たない**（設計書 §3.7）。
 *   日付の入り方だけで決まる。フラグを持つと「日付とフラグが食い違う」状態を作れてしまう。
 */
class ScheduleStep extends Model
{
    protected $table = 'schedule_steps';

    protected $fillable = [
        'name',
        'category',
        'planned_start',
        'planned_end',
        'actual_start',
        'actual_end',
        'sort_order',
        'notes',
        // 取込元。⚠ enum にしない（「手入力 = null」と Manual の 2 通りができてしまう）
        'source',
        'created_by',
        'updated_by',
    ];

    /**
     * ⚠ **住宅事業の工程は実績を持たない**（設計書 §3.3）。画面から消すだけにすると、
     *   `Validator::validated()` が未送信キーを結果に含めないため `update($validated)` が
     *   **そのカラムに触れず旧値を残す**（Bug #38 と同型）。書き込み経路が増えても漏れないよう、
     *   ここ 1 箇所で正規化する。
     *
     * ⚠ **`schedulable` は `associate()` 済みなのでクエリは増えない。** 親が未解決のときは
     *   何もしない（保存前に親を紐づけていない呼び出しは元々成立しない）。
     */
    protected static function booted(): void
    {
        static::saving(function (self $step): void {
            $owner = $step->schedulable;

            if ($owner !== null && ! $owner->scheduleTracksActuals()) {
                $step->actual_start = null;
                $step->actual_end   = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'category'      => ScheduleStepCategory::class,
            'planned_start' => 'date',
            'planned_end'   => 'date',
            'actual_start'  => 'date',
            'actual_end'    => 'date',
            'sort_order'    => 'integer',
        ];
    }

    public function schedulable(): MorphTo
    {
        return $this->morphTo();
    }

    // ============================================================
    // 行の種別（設計書 §3.7）
    // ============================================================

    /**
     * 描画できるか。
     *
     * ⚠ **描画しない行を一覧から消さないこと。** 日付が入っていない行を黙って消すと、
     *   利用者は「保存できていない」と誤解する。一覧には残して期間欄に「日付未設定」と出す。
     */
    public function isDrawable(): bool
    {
        return $this->planned_start !== null || $this->actual_start !== null;
    }

    /** ◆ 1 個で描く行（棒にする期間が無い） */
    public function isMilestone(): bool
    {
        return $this->isDrawable()
            && $this->planned_end === null
            && $this->actual_start === null
            && $this->actual_end === null;
    }

    // ============================================================
    // 描画区間（設計書 §5.2）— 画面の棒は 1 本だけ
    // ============================================================

    /**
     * ⚠ **実績を優先する。** 「予定 5/18〜9/30・実績 6/1〜10/16」の工程は 6/1〜10/16 の 1 本。
     *   詳細画面では遅れは棒から読めない（案1 を選んだということ）。遅れはボードで見る。
     */
    public function drawStart(): ?CarbonInterface
    {
        return $this->actual_start ?? $this->planned_start;
    }

    /**
     * ⚠ 実績開始があって実績終了が無いときは「進行中」なので右端を今日まで伸ばす。
     *
     * ⚠ **`$today` は必須。既定値を持たせない**（`ScheduleStepStatus` と同じ方針）。
     *   内部で `now()` に落ちる経路を残すと、テストが実行日に依存して
     *   「時刻を凍結したつもりで効いていない」状態を作れてしまう。
     *   呼び出し側（`ScheduleCardService` / `ScheduleBoardService`）は必ず渡している。
     */
    public function drawEnd(CarbonInterface $today): ?CarbonInterface
    {
        if ($this->actual_start !== null) {
            return $this->actual_end ?? $today;
        }

        if ($this->planned_start === null) {
            return null;
        }

        return $this->planned_end ?? $this->planned_start;
    }

    // ============================================================
    // 遅延・進捗・状態（判定は App\Support\ScheduleStepStatus に集約。Bug #46）
    // ⚠ 「遅延・進捗」は実績を持つ親（不動産）用、「状態」は実績を持たない親（住宅事業）用で別の軸。
    // ============================================================

    public function delayDays(CarbonInterface $today): int
    {
        return ScheduleStepStatus::delayDays($this->planned_end, $this->actual_end, $today);
    }

    public function isLate(CarbonInterface $today): bool
    {
        return ScheduleStepStatus::isLate($this->planned_end, $this->actual_end, $today);
    }

    public function progress(): string
    {
        return ScheduleStepStatus::progress($this->actual_start, $this->actual_end);
    }

    /**
     * 日付だけで決まる状態（設計書 §4.1）。住宅事業の画面が使う。
     *
     * ⚠ **`planned_*` を直接見る**（`drawStart()` / `drawEnd()` を経由しない）。
     *   `drawEnd()` は「実績開始があれば今日まで伸ばす」を含むので分岐が二重になる。
     *   実績を持つ親では使わない（そちらは遅延と進捗で見る）。
     */
    public function dateState(CarbonInterface $today): string
    {
        return ScheduleStepStatus::dateState($this->planned_start, $this->planned_end, $today);
    }

    public function stateLabel(CarbonInterface $today): string
    {
        return ScheduleStepStatus::STATE_LABELS[$this->dateState($today)];
    }

    /**
     * 左カラムに出す期間テキスト（`3/16〜7/03`）。描けない行は「日付未設定」。
     *
     * ⚠ `drawEnd()` と同じ理由で `$today` は必須。
     */
    public function periodText(CarbonInterface $today): string
    {
        if (! $this->isDrawable()) {
            return '日付未設定';
        }

        if ($this->isMilestone()) {
            return $this->drawStart()->format('n/d');
        }

        return $this->drawStart()->format('n/d') . '〜' . $this->drawEnd($today)->format('n/d');
    }
}
