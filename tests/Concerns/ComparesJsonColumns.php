<?php

namespace Tests\Concerns;

/**
 * JSON の列の値を、キーの並びに頼らずに比べる。
 *
 * MySQL の JSON 列はキーを並べ替えて返す（短い順・同じ長さは辞書順）。SQLite は保存した順のまま返すので、
 * assertSame でそのまま比べるとテストは SQLite でだけ通る。assertEquals は 0 と null を同じとみなすので使わない。
 */
trait ComparesJsonColumns
{
    /** 連想配列のキーを（入れ子まで）並べ替えてから assertSame で比べる。リスト（0, 1, 2 …）の並びはそのまま比べる */
    protected function assertSameIgnoringKeyOrder(array $expected, mixed $actual, string $message = ''): void
    {
        $this->assertIsArray($actual, $message);
        $this->assertSame($this->sortKeysDeep($expected), $this->sortKeysDeep($actual), $message);
    }

    private function sortKeysDeep(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->sortKeysDeep($item) : $item, $value);
    }
}
