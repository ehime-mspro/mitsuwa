{{-- 並び替えできる列見出し（前設計書 §4.2 / §5.6、設計書 2026-08-28 §7.1）

使い方:
  <x-sortable-th column="area" :sort="$sort" :columns="$sortColumns" align="right" link-style="padding: 14px 20px;" />
  <x-sortable-th column="occupancy" :sort="$sort" :columns="$sortColumns" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />

props:
  column     … ?sort に載るキー（コントローラの許可リストと揃える）
  columns    … その画面の SORT_COLUMNS（日本語ラベルと「向きの言い方」）
  sort       … App\Support\ListSort|null（コントローラから渡す）
  align      … left | center | right（既定 center）。<th> の text-align と <a> の justify-content
  linkClass  … <a> に足すクラス。**パディングはここ**（Tailwind の responsive を使いたい画面用）
  linkStyle  … <a> に足す inline style。**パディングはここ**（inline style で組まれた画面用）

⚠ **ラベルはここに書かない。** バー（x-sort-bar）が同じ列名を出すので、
   2 箇所に文字列を置くと片方だけ直す事故が起きる（Bug #41 / #46）。
⚠ column を打ち間違えると `$columns[$column]` が未定義になり、Laravel が警告を
   ErrorException へ変えて**この画面が 500 になる**。黙って既定順へ落ちるより良い
   （設計書 §7.1）。配線は SortableListWiringTest が静的に守っている。
⚠ 属性式に &quot; を書かないこと。本番の view:cache でだけ 500 になる（Bug #21）。
⚠ パディングは <th> ではなく中の <a> に載せる。**見出しセル全体を押せるようにするため**で、
   <th> 側に残すと文字の上しか反応しない。HTML を見ても分からないので画面で確かめる（Bug #43）。
⚠ **<a> の中は「ラベル → 矢印」の順にする。** テストの sortLinkFor() が
   <a …> の直後（span 1 つは可）にラベルが来ることを要求しているので、矢印を先に置くと
   リンクを見つけられない。
⚠ JS は 1 行も使わない。ただのリンク。
⚠ `color` は inline style なので `hover:text-*` / `focus:text-*` は**効かない**（inline が勝つ）。
   文字色を状態で変えたいなら inline 側を CSS 変数にするか app.css へ逃がすこと。
⚠ <a> の高さは自分の content + padding で決まり、行の高さには追従しない。
   将来どこかの見出しが 2 行になると、並び替え可能な列だけ当たり判定が縮む（HTML では見えない）。
--}}
@props([
    'column',
    'columns',
    'sort' => null,
    'align' => 'center',
    'linkClass' => '',
    'linkStyle' => '',
])
@php
    $label = $columns[$column]['label'];
    $state = \App\Support\ListSort::stateOf($sort, $column);

    $ariaSort = match ($state) {
        \App\Support\ListSort::ASC => 'ascending',
        \App\Support\ListSort::DESC => 'descending',
        default => 'none',
    };

    $justify = match ($align) {
        'left' => 'flex-start',
        'right' => 'flex-end',
        default => 'center',
    };

    $iconColor = $state === null ? '#D1D5DB' : '#059669';
    $labelColor = $state === null ? 'inherit' : '#047857';
@endphp
<th {{ $attributes->merge([
        'class' => 'text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap',
        'style' => 'padding: 0; text-align: ' . $align . ';',
    ]) }} aria-sort="{{ $ariaSort }}">
    <a href="{{ \App\Support\ListSort::url(request(), $column, $sort) }}"
       class="{{ trim('sortable-th-link hover:bg-gray-100 transition-colors ' . $linkClass) }}"
       style="display: flex; align-items: center; justify-content: {{ $justify }}; gap: 5px; text-decoration: none; cursor: pointer; user-select: none; color: {{ $labelColor }}; {{ $linkStyle }}">
        {{ $label }}
        <span style="flex-shrink: 0; width: 12px; height: 12px; color: {{ $iconColor }};">
            @if($state === \App\Support\ListSort::ASC)
                <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 15 12 8 18 15"/></svg>
            @elseif($state === \App\Support\ListSort::DESC)
                <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 16 18 9"/></svg>
            @else
                <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="7 15 12 20 17 15"/><polyline points="7 9 12 4 17 9"/></svg>
            @endif
        </span>
    </a>
</th>
