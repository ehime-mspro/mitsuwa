{{-- フォーム操作ボタン共通コンポーネント（フッター固定バー）

使い方:
  <x-form-actions submit-label="更新する" :cancel-url="route('xxx.show', $model)" />
  <x-form-actions submit-label="登録する" :cancel-url="route('xxx.index')" />

挙動:
  - 画面下部に常時固定（position: fixed）
  - サイドバー展開/折り畳みに連動して left オフセット（layouts/app.blade.php の sidebarExpanded を参照）
  - スマホ（< 1024px）ではサイドバー非表示なので left: 0（CSS media query で上書き）
  - フォーム末尾にバー高さ分のスペーサを自動挿入し、最終入力欄が隠れないようにする
--}}
@props([
    'submitLabel' => '登録する',
    'cancelUrl'   => null,
])

{{-- フッター高さ分のスペース確保（フォーム末尾の入力欄がバーに隠れないように） --}}
<div style="height: 80px;" aria-hidden="true"></div>

{{-- フッター固定バー（Alpine :style 一本で記述。style と :style の併用は禁止） --}}
<div data-form-actions-bar
    :style="{
        position: 'fixed',
        bottom: '0',
        right: '0',
        left: sidebarExpanded ? '220px' : '56px',
        zIndex: 50,
        background: 'rgba(255, 255, 255, 0.96)',
        backdropFilter: 'blur(10px)',
        WebkitBackdropFilter: 'blur(10px)',
        borderTop: '1px solid #e5e7eb',
        padding: '14px 28px',
        display: 'flex',
        gap: '12px',
        justifyContent: 'flex-end',
        alignItems: 'center',
        boxShadow: '0 -4px 16px rgba(0,0,0,0.06)',
    }">
    <button type="submit"
        style="padding: 10px 22px; background: #059669; color: #fff; border: none;
               border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer;
               display: inline-flex; align-items: center; gap: 6px; transition: background-color 0.15s;"
        onmouseover="this.style.background='#047857'"
        onmouseout="this.style.background='#059669'">
        {{ $submitLabel }}
    </button>
    @if($cancelUrl)
        <a href="{{ $cancelUrl }}"
            style="padding: 10px 22px; background: #fff; color: #374151; border: 2px solid #9ca3af;
                   border-radius: 6px; font-size: 14px; font-weight: 700;
                   text-decoration: none; display: inline-flex; align-items: center; transition: background-color 0.15s;"
            onmouseover="this.style.background='#f9fafb'"
            onmouseout="this.style.background='#fff'">
            キャンセル
        </a>
    @endif
</div>

{{-- モバイル対応: lg breakpoint 未満ではサイドバー非表示なので left を 0 に上書き --}}
@once
<style>
@media (max-width: 1023px) {
    [data-form-actions-bar] { left: 0 !important; }
}
</style>
@endonce
