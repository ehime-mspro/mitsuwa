<?php

namespace App\Support\Approval;

use Illuminate\Database\Eloquent\Builder;

/**
 * 人の名前の探し方（決裁台帳の申請者〈段階4 D21〉・⑦ 利用者の管理〈段階5 D25〉）。2 つの画面が同じ規則で探すよう 1 か所に置く。
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

    /**
     * users の問い合わせに「登録名の空白を除いた名前が、どの語も含む」を足す（語が無ければ何も足さない）。
     * ⚠ 列は表の名前を付けて書く（JOIN を足しても名前の列が曖昧にならない）
     *
     * @param list<string> $terms
     */
    public static function whereNameHasAll(Builder $users, array $terms): void
    {
        foreach ($terms as $term) {
            $users->whereRaw("replace(replace(users.name, ' ', ''), '　', '') like ?", ["%{$term}%"]);
        }
    }
}
