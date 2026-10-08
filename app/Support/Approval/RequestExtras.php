<?php

namespace App\Support\Approval;

use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use Carbon\CarbonInterface;

/**
 * 追加の入力欄（坪数・坪単価・担当者・契約予定日。要件 5.5.5・段階5 設計書 §5.4〜§5.6・D2・D12）。並びと表示はここ 1 か所。
 *
 * - 種類の `uses_{キー}` が「使う」の欄だけを申請の画面に出し、保存する（使わない欄は保存のときに空にする）
 * - 担当者と契約予定日は、使う種類では提出に必須（D2）。坪数・坪単価は空でもよい
 * - 控え（`RequestSnapshot`）には、提出したときに使っていた欄だけを入れる（あとで種類の設定を変えても、提出した申請の見た目は変わらない）
 */
final class RequestExtras
{
    /** キー（申請の列名。種類の列は `uses_` を付けたもの）=> 名前。この並びで出す */
    public const FIELDS = [
        'tsubo'         => '坪数',
        'tsubo_price'   => '坪単価',
        'staff'         => '担当者',
        'contract_date' => '契約予定日',
    ];

    /** 使う種類では提出に必須の欄（D2） */
    public const REQUIRED = ['staff', 'contract_date'];

    /** 坪数の上限（小数第 2 位まで）・坪単価の上限（円・12 桁）・担当者の文字数 */
    public const TSUBO_MAX = '99999.99';

    public const TSUBO_PRICE_MAX = 999_999_999_999;

    public const STAFF_MAX = 50;

    /**
     * この種類が使う欄（並びは FIELDS のとおり）
     *
     * @return list<string>
     */
    public static function usedBy(?ApprovalType $type): array
    {
        if ($type === null) {
            return [];
        }

        return array_values(array_filter(array_keys(self::FIELDS), fn (string $key) => (bool) $type->getAttribute("uses_{$key}")));
    }

    /**
     * 申請の今の値（この種類が使う欄だけ。控えと同じ形＝日付は Y-m-d・坪数は文字・坪単価は数）
     *
     * @return array<string, string|int|null>
     */
    public static function valuesOf(ApprovalRequest $request, ?ApprovalType $type): array
    {
        $values = [];
        foreach (self::usedBy($type) as $key) {
            $value = $request->getAttribute($key);
            $values[$key] = $value instanceof CarbonInterface ? $value->format('Y-m-d') : $value;
        }

        return $values;
    }

    /**
     * 控えの値を、FIELDS の並びにそろえる（MySQL は JSON のキーを並べ替えて返すので、並びに頼らない）
     *
     * @param mixed $extras 控えの `extras`（無ければ空）
     * @return array<string, string|int|null>
     */
    public static function ordered(mixed $extras): array
    {
        $extras = is_array($extras) ? $extras : [];
        $values = [];
        foreach (array_keys(self::FIELDS) as $key) {
            if (array_key_exists($key, $extras)) {
                $values[$key] = $extras[$key];
            }
        }

        return $values;
    }

    /** 表示（坪数「38.5坪」・坪単価「1,083,000円」・担当者はそのまま・契約予定日「2026/10/20」。空なら null） */
    public static function display(string $key, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($key) {
            'tsubo'         => rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.') . '坪',
            'tsubo_price'   => number_format((int) $value) . '円',
            // 日付（Y-m-d の文字。時刻を持たないので日本時間に直さない）
            'contract_date' => str_replace('-', '/', substr((string) $value, 0, 10)),
            default         => (string) $value,
        };
    }
}
