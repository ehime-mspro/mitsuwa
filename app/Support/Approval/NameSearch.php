<?php

namespace App\Support\Approval;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * 人の名前の探し方（決裁台帳の申請者〈段階4 D21〉・⑦ 利用者の管理〈段階5 D25〉・台帳の紙の決裁の申請者〈段階5 §5.13〉）。同じ規則で探すよう 1 か所に置く。
 *
 * 空白（全角を含む）で分けた語の**どれも**含む人に当て、登録名の半角・全角の空白は無視する
 * （「山田太郎」で「山田 太郎」も、「田　郎」でも当たる）。`LIKE` の `%` と `_` は逃がさない（アプリのほかの検索と同じ）。
 */
final class NameSearch
{
    /**
     * 空白（全角を含む）で分けた語（空の語は除く）
     *
     * @return list<string>
     */
    public static function terms(?string $text): array
    {
        return $text === null ? [] : (preg_split('/[\s\x{3000}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** 紙の決裁の申請者の列（台帳の文字のまま） */
    public const PAPER_APPLICANT = 'approval_paper_records.applicant_name';

    /** 名前の列として渡せるもの（SQL にそのまま書くので、決まった列だけ） */
    private const COLUMNS = ['users.name', self::PAPER_APPLICANT];

    /**
     * 問い合わせに「名前の列の空白を除いた文字が、どの語も含む」を足す（語が無ければ何も足さない）。既定は users の登録名
     * ⚠ 列は表の名前を付けて書く（JOIN を足しても名前の列が曖昧にならない）。決まった列だけを受け取る（SQL に埋め込むため）
     *
     * @param list<string> $terms
     */
    public static function whereNameHasAll(Builder $query, array $terms, string $column = 'users.name'): void
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("名前の列として使えない列です: {$column}");
        }

        foreach ($terms as $term) {
            $query->whereRaw("replace(replace({$column}, ' ', ''), '　', '') like ?", ["%{$term}%"]);
        }
    }
}
