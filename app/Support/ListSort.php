<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * 一覧の並び替え指定（?sort=xxx&dir=asc|desc）の解釈と、見出しリンクの URL 生成。
 *
 * 設計書: docs/superpowers/specs/2026-08-25-tenant-list-sorting-design.md
 *
 * ⚠ ここは「指定をどう読むか」だけを持ち、**実際の並べ替えはしない**。
 *   物件一覧は PHP・部屋一覧は SQL と方法が違うため（設計書 §3.2）。
 *
 * ⚠ 「—」を末尾へ回す規則はここには無い。**列ごとに違う**ので各画面が持つ（設計書 §4.4）。
 *
 * ⚠ 許可リストはコントローラ（fromRequest の第 2 引数）とビュー（x-sortable-th の column）の
 *   2 箇所に分かれており、その一致は各一覧の往復 Feature テストが担保している。
 */
class ListSort
{
    public const ASC = 'asc';

    public const DESC = 'desc';

    /**
     * ⚠ private なのは意図。**作る経路を fromRequest() 1 本に絞る**ことで、
     *   $key が必ず許可リストの中・$direction が必ず asc|desc であることを型で保証する
     *   （UnitController の match が default アーム無しで書ける根拠）。
     *   代償として、テストから ListSort を直接組み立てることはできない。
     */
    private function __construct(
        public readonly string $key,
        public readonly string $direction,
    ) {
    }

    /**
     * リクエストから並び替え指定を読む。
     *
     * ⚠ 不正・未知・未指定はすべて null（＝既定順）へ落とす。**500 にしない。**
     *   `?sort[]=a` のように配列で来ることがあるので `is_string()` が要る。
     *   `?sort=` は実 HTTP だと ConvertEmptyStringsToNull が null にし、
     *   `Request::create()` だと '' のまま届く（Bug #31）。どちらも許可リストを通らない。
     *
     * @param  list<string>  $allowed  この画面で並び替えを許すキー
     */
    public static function fromRequest(Request $request, array $allowed): ?self
    {
        $key = $request->query('sort');

        if (! is_string($key) || ! in_array($key, $allowed, true)) {
            return null;
        }

        $direction = $request->query('dir') === self::ASC ? self::ASC : self::DESC;

        return new self($key, $direction);
    }

    public function isAscending(): bool
    {
        return $this->direction === self::ASC;
    }

    /**
     * その列を今押したときの次の向き。null は「並び替え解除（既定順へ戻す）」。
     * 既定 → 降順 → 昇順 → 既定 の 3 状態（設計書 §4.2）。
     *
     * ⚠ 1 回目が降順なのは、金額と率なので「多い順」を先に見たいため。
     */
    public static function next(?self $current, string $key): ?string
    {
        if ($current === null || $current->key !== $key) {
            return self::DESC;
        }

        return $current->isAscending() ? null : self::ASC;
    }

    /** その列の現在の向き。並び替えに使っていなければ null */
    public static function stateOf(?self $current, string $key): ?string
    {
        return $current !== null && $current->key === $key ? $current->direction : null;
    }

    /**
     * 見出しリンクの URL。現在の絞り込みは維持しつつ並び替えだけ変える。
     *
     * ⚠ page は必ず落とす。並べ替えた直後に 5 ページ目に居るのはおかしい（設計書 §4.3-5）。
     * ⚠ null の値は '' へ正規化してから Arr::query() に渡す。怠ると
     *   `?operation_status=` のような空の絞り込みが**リンクから丸ごと消える**
     *   （実測: Arr::query(['a'=>null,'b'=>'','c'=>'x']) === 'b=&c=x'。Bug #31）。
     */
    public static function url(Request $request, string $key, ?self $current): string
    {
        $query = $request->query();
        unset($query['page']);

        $next = self::next($current, $key);

        if ($next === null) {
            unset($query['sort'], $query['dir']);
        } else {
            $query['sort'] = $key;
            $query['dir'] = $next;
        }

        return self::buildUrl($request, $query);
    }

    /**
     * 並び順だけを解除する URL（バーの「解除」。設計書 §6）。
     *
     * ⚠ **絞り込みは残す。** フィルタごと初期化する「クリア」ボタン（route(...) への素のリンク）
     *   とは役割が違う。両方が同じ結果になるなら、バーに解除を出す意味が無い。
     * ⚠ page も落とす。並びが変わるので 5 ページ目に居るのはおかしい（url() と同じ規約）。
     */
    public static function clearUrl(Request $request): string
    {
        $query = $request->query();
        unset($query['page'], $query['sort'], $query['dir']);

        return self::buildUrl($request, $query);
    }

    /**
     * クエリ配列から URL を組み立てる。
     *
     * ⚠ null の値は '' へ正規化してから Arr::query() に渡す。怠ると
     *   `?occupancy=` のような空の絞り込みが**リンクから丸ごと消える**
     *   （実測: Arr::query(['a'=>null,'b'=>'','c'=>'x']) === 'b=&c=x'。Bug #31）。
     * ⚠ url() と clearUrl() で**同じ正規化**を通すためにここに集約している。
     *   片方だけ直すと、見出しリンクと解除リンクで絞り込みの残り方が食い違う。
     *
     * @param  array<string, mixed>  $query
     */
    private static function buildUrl(Request $request, array $query): string
    {
        $queryString = Arr::query(array_map(fn ($value) => $value ?? '', $query));

        return $request->url() . ($queryString === '' ? '' : '?' . $queryString);
    }
}
