{{-- 並び順をフィルターフォームに持ち回す hidden（設計書 §4.3-4）

使い方:
  <form id="filter-form" method="GET" …>
      <x-sort-hidden :sort="$sort" />
      …

⚠ これが無いと、並び替え中にフィルタを変えた瞬間に GET で送り直されて
   ?sort と ?dir が落ち、並び順が黙って既定へ戻る。
⚠ 並び替えていないときは何も出さない。出すと ?sort= が URL に現れて汚れる。
⚠ 「クリア」は route(...) への素のリンクなので、従来どおり全部が初期化される（それが仕様）。
--}}
@props(['sort' => null])
@if($sort)
    <input type="hidden" name="sort" value="{{ $sort->key }}">
    <input type="hidden" name="dir" value="{{ $sort->direction }}">
@endif
