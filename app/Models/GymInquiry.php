<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 体験予約（外部 DB 参照）
 *
 * 接続先: mitsuwa-ud_zeel-b データベース（config/database.php の 'zeal' 接続）
 * このテーブルへの書き込みは行わない（Spreadsheet 同期側で更新）。
 * save() / delete() / forceDelete() は例外をスローして誤操作を防止する。
 */
class GymInquiry extends Model
{
    /** 外部 DB 接続を使用 */
    protected $connection = 'zeal';

    protected $table = 'gym_inquiries';

    /**
     * 書き込み禁止のため fillable は空
     * 読み取り専用の使用に限定する
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'inquiry_date' => 'date',
            'trial_date'   => 'date',
        ];
    }

    /** 紐付いている会員（gym_inquiry_id で参照）*/
    public function member(): HasOne
    {
        return $this->hasOne(ZealMember::class, 'gym_inquiry_id');
    }

    /**
     * 表示用契約プラン名（運用上 `contract_plan` に含まれる「N枠」表記を除去した形）
     *
     * gym_inquiries.contract_plan は外部 Spreadsheet 由来の文字列で、
     * zeal_plans.name と同じく「1枠 通い放題」「フリープラン（1枠）」のような
     * 同時予約枠数が混入している。会員一覧と同じく枠数を表示しない方針（案A）。
     * ロジックは ZealPlan::getDisplayNameAttribute と完全に同一。
     */
    public function getContractPlanDisplayAttribute(): string
    {
        $value = $this->contract_plan ?? '';
        // 括弧で囲まれた N 枠表記を除去（「（1枠）」「(2枠)」等）
        $value = preg_replace('/[（(]\s*[0-9０-９]+\s*枠\s*[）)]/u', '', $value);
        // 括弧なし N 枠表記を除去（「1枠 通い放題」「2枠 通い放題」等。全角スペース U+3000 も含む）
        $value = preg_replace('/[0-9０-９]+\s*枠[\s\x{3000}]*/u', '', $value);
        return trim($value);
    }

    /**
     * 書き込み禁止: 誤操作防止のため save() をオーバーライド
     *
     * @throws \RuntimeException
     */
    public function save(array $options = []): bool
    {
        throw new \RuntimeException('gym_inquiries は読み取り専用です。書き込みは Spreadsheet 同期側で行ってください。');
    }

    /**
     * 書き込み禁止: 誤操作防止のため delete() をオーバーライド
     *
     * @throws \RuntimeException
     */
    public function delete(): bool|null
    {
        throw new \RuntimeException('gym_inquiries は読み取り専用です。削除操作は許可されていません。');
    }
}
