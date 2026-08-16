<?php

namespace App\Support;

/**
 * 「階」の表記ゆれを整数へ正規化する（周辺ビル調査の Excel 取込）。
 *
 * 地下は負数（B1 = -1）。`AreaBuildingTenant::floorLabel()` がこの約束で表示する。
 *
 * ⚠ 正規化をコントローラに散らさない（`AreaTenantStatus::fromRawLabel()` と同じ流儀）。
 *   取込プレビュー（JS）とサーバ（PHP）で判定が割れると、画面が「取り込める」と言った行が
 *   サーバで無音で捨てられる（Bug #41）。**語彙は下の 3 つの定数が正本**で、
 *   `import.blade.php` の `AREA_IMPORT_FLOOR_TOKENS` は JSON でこれと同じ値を持つ。
 *   一致は `AreaBuildingImportTest::test_floor_vocabulary_matches_between_php_and_js` が固定する。
 *
 * ⚠ 2026-08-17 のレビューまで、`'1F'` `'B1'` `'2階'` `'地下1階'` は
 *   **全部 null に落ちて無警告で捨てられていた**（Excel でいちばん自然な書き方）。
 *   詳細画面は `orderByDesc('floor')` で並べて `floorLabel()` を出すので、
 *   取り込んだテナントが全行「—」になり階の並びも消えていた。
 */
class FloorNumber
{
    /** 地下を表す接頭辞 */
    public const BASEMENT_PREFIXES = ['B', 'Ｂ', '地下'];

    /** 地上を表す接頭辞（落とすだけで符号は変えない） */
    public const ABOVE_GROUND_PREFIXES = ['地上'];

    /**
     * 階を表す接尾辞。
     * ⚠ **長いものから並べること。** '階建て' より先に '階' を見ると '10階建て' が
     *   '10建て' になって読めなくなる。
     */
    public const FLOOR_SUFFIXES = ['階建て', '階建', '階', 'Ｆ', 'F', 'f'];

    /**
     * @param  bool  $allowBasement  false なら地下（負数）を「読めない」として扱う（総階数用）
     * @return int|null|false null = 空欄 / false = 読めない
     */
    public static function parse(mixed $raw, bool $allowBasement = true): int|null|false
    {
        if ($raw === null) {
            return null;
        }
        if (! is_scalar($raw)) {
            return false;
        }

        // 全角数字 → 半角。カンマ・空白（U+3000 含む）は落とす
        $s = mb_convert_kana(trim((string) $raw), 'n');
        $s = preg_replace('/[,，\s\x{3000}]/u', '', $s);

        if ($s === '') {
            return null;
        }

        foreach (self::ABOVE_GROUND_PREFIXES as $prefix) {
            if (str_starts_with($s, $prefix)) {
                $s = substr($s, strlen($prefix));
                break;
            }
        }

        $basement = false;
        foreach (self::BASEMENT_PREFIXES as $prefix) {
            if ($s !== '' && str_starts_with($s, $prefix)) {
                $basement = true;
                $s = substr($s, strlen($prefix));
                break;
            }
        }

        foreach (self::FLOOR_SUFFIXES as $suffix) {
            if ($s !== '' && str_ends_with($s, $suffix)) {
                $s = substr($s, 0, -strlen($suffix));
                break;
            }
        }

        if (preg_match('/\A-?\d+\z/', $s) !== 1) {
            return false;
        }

        $value = (int) $s;

        if ($basement) {
            // 'B-1' のように符号が二重に付いた表記は読めないものとして扱う
            if ($value < 0) {
                return false;
            }
            $value = -$value;
        }

        if (! $allowBasement && $value < 0) {
            return false;
        }

        return $value;
    }
}
