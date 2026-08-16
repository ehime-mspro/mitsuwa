{{-- 期待: $building / $tenant

     現況リストと退去済みリストの両方から使う（退去済みも直せないと、退去日の打ち間違いが
     二度と直せなくなる）。同じマークアップを 2 か所に写すと片方だけ直す事故が起きるので
     partial に切り出している（Bug #41 / #44）。 --}}
@if(auth()->user()->role->isManagerOrAbove())
    <a href="{{ route('tenant.area-buildings.tenants.edit', [$building, $tenant]) }}"
       class="text-xs font-semibold text-emerald-700 px-3 py-1 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 transition-colors">編集</a>
@endif
@if(auth()->user()->role->isExecutive())
    {{-- ⚠ 行ごとの削除なので <x-delete-confirm-modal> は使えない（show.blade.php 末尾のコメント参照）。
         ⚠ テナント名は利用者の自由入力。JS 文字列へ生で差し込まず Js::from() を通す
            （`'` を含む名前で壊れる）。addslashes は使わない — 改行や U+2028 を逃がさず、
            さらに {{ }} の e() と二重にかかる。前例: zeal/plans/index.blade.php:143 --}}
    <form method="POST" action="{{ route('tenant.area-buildings.tenants.destroy', [$building, $tenant]) }}"
          onsubmit="return confirm({{ \Illuminate\Support\Js::from($tenant->name ?: 'この行') }} + ' を削除します。よろしいですか？');">
        @csrf
        @method('DELETE')
        <button type="submit"
                class="text-xs font-semibold text-red-700 px-3 py-1 border border-red-200 rounded bg-red-50 hover:bg-red-100 transition-colors">削除</button>
    </form>
@endif
