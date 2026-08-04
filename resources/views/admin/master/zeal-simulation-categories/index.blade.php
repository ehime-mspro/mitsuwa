@extends('layouts.app')

@section('title', 'ZEAL 試算表 項目マスター')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>システム管理</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">ZEAL 試算表 項目マスター</span>
@endsection

@section('content')
<div x-data="zealCategoryReorder()">

    <div class="flex items-center justify-between mb-5">
        <h1 class="text-lg font-bold text-gray-900">ZEAL 試算表 項目マスター</h1>
        <a href="{{ route('admin.master.zeal-simulation-categories.create') }}"
           style="display: inline-flex; align-items: center; padding: 8px 16px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;">
            + 新規項目を追加
        </a>
    </div>

    <div style="background: #fefce8; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
        <div style="font-weight: 600; font-size: 14px; color: #92400e; margin-bottom: 6px;">項目マスターについて</div>
        <ul style="font-size: 12px; color: #a16207; margin: 0; padding-left: 18px; line-height: 1.8;">
            <li>試算表の縦軸（賃料・委託費・売上 等）をここで管理します</li>
            <li>「計算タイプ」で挙動を切り替えます：<strong>手入力</strong> / <strong>固定額</strong>（毎月同額のデフォルト）/ <strong>売上連動</strong>（売上 × 率）/ <strong>システム計算</strong></li>
            <li><span style="background: #d1fae5; padding: 2px 8px; border-radius: 4px; font-weight: 600;">システム固定</span> 行（経費計・営業利益・累計利益）は削除・グループ変更ができません</li>
            <li>左端の <span style="display:inline-block; vertical-align:middle;">⋮⋮</span> ハンドルをドラッグして並び順を変更できます。ドロップすると自動で保存されます</li>
        </ul>
    </div>

    {{-- 並替え成功メッセージ（右下フローティングトースト） --}}
    <div x-show="reorderMessage"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         x-cloak
         style="position: fixed; bottom: 24px; right: 24px; background: #059669; color: white; padding: 12px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; box-shadow: 0 10px 25px rgba(0,0,0,0.18); z-index: 9999; display: inline-flex; align-items: center; gap: 8px;">
        <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
        </svg>
        <span x-text="reorderMessage"></span>
    </div>

    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
        <table style="width: 100%; border-collapse: collapse; min-width: 760px;">
            <thead>
                <tr style="background: #f9fafb;">
                    <th style="padding: 12px 8px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 56px;"></th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 130px;">コード</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb;">項目名</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 90px;">グループ</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 160px;">計算タイプ</th>
                    <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 120px;">デフォルト額</th>
                    <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 80px;">率(%)</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 80px;">状態</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 130px;">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($categories as $idx => $cat)
                    <tr data-id="{{ $cat->id }}"
                        data-index="{{ $idx }}"
                        class="zeal-cat-row"
                        draggable="true"
                        @dragstart="handleDragStart($event)"
                        @dragover.prevent="handleDragOver($event)"
                        @dragleave="handleDragLeave($event)"
                        @drop.prevent="handleDrop($event)"
                        @dragend="handleDragEnd($event)"
                        style="border-bottom: 1px solid #f3f4f6;">

                        {{-- ドラッグハンドル列 --}}
                        <td style="padding: 10px 8px; text-align: center;">
                            <span class="zeal-drag-handle" title="ドラッグで並び替え"
                                  style="cursor: grab; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px; background: #f3f4f6; border: 1px solid #d1d5db; color: #4b5563;">
                                <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="currentColor">
                                    <circle cx="9" cy="6" r="2"/><circle cx="15" cy="6" r="2"/>
                                    <circle cx="9" cy="12" r="2"/><circle cx="15" cy="12" r="2"/>
                                    <circle cx="9" cy="18" r="2"/><circle cx="15" cy="18" r="2"/>
                                </svg>
                            </span>
                        </td>

                        <td style="padding: 12px 16px; font-size: 12px; color: #374151; font-family: 'SFMono-Regular','Consolas',monospace;">{{ $cat->code }}</td>
                        <td style="padding: 12px 16px; font-size: 13px; color: #111827; font-weight: 600;">
                            {{ $cat->name }}
                            @if($cat->is_system)
                                <span style="display: inline-block; margin-left: 6px; padding: 2px 8px; background: #d1fae5; color: #047857; font-size: 10px; font-weight: 700; border-radius: 4px;">システム固定</span>
                            @endif
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span style="display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; background: {{ $cat->group_type->backgroundColor() }}; color: #374151;">
                                {{ $cat->group_type->label() }}
                            </span>
                        </td>
                        <td style="padding: 12px 16px; text-align: center; font-size: 12px; color: #4b5563;">{{ $cat->calc_type->label() }}</td>
                        <td style="padding: 12px 16px; text-align: right; font-size: 13px; color: #111827; font-weight: 500;">
                            @if($cat->default_amount !== null)
                                {{ number_format($cat->default_amount) }}円
                            @else
                                <span style="color: #9ca3af;">—</span>
                            @endif
                        </td>
                        <td style="padding: 12px 16px; text-align: right; font-size: 13px; color: #111827; font-weight: 500;">
                            @if($cat->rate_percent !== null)
                                {{ rtrim(rtrim(number_format($cat->rate_percent, 3), '0'), '.') }}%
                            @else
                                <span style="color: #9ca3af;">—</span>
                            @endif
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            @if($cat->is_active)
                                <span style="display: inline-block; padding: 2px 10px; background: #d1fae5; color: #047857; font-size: 11px; font-weight: 600; border-radius: 999px;">有効</span>
                            @else
                                <span style="display: inline-block; padding: 2px 10px; background: #f3f4f6; color: #6b7280; font-size: 11px; font-weight: 600; border-radius: 999px;">無効</span>
                            @endif
                        </td>
                        <td style="padding: 12px 16px; text-align: center; white-space: nowrap;">
                            <a href="{{ route('admin.master.zeal-simulation-categories.edit', $cat) }}"
                               style="display: inline-block; padding: 4px 12px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; text-decoration: none; background: #fff;">編集</a>
                            @if(!$cat->is_system)
                                <form action="{{ route('admin.master.zeal-simulation-categories.destroy', $cat) }}" method="POST" style="display: inline-block; margin-left: 4px;"
                                      onsubmit="return confirm('「{{ $cat->name }}」を削除しますか？既存試算表のセル値も削除されます。');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            style="padding: 4px 12px; font-size: 12px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 4px; background: #fff; cursor: pointer;">削除</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" style="padding: 40px 16px; text-align: center; color: #9ca3af; font-size: 13px;">
                            項目が登録されていません。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <div class="scroll-hint-text">← スクロールできます →</div>
        </div>
    </div>

    <div style="margin-top: 12px; font-size: 11px; color: #6b7280;">
        全 {{ count($categories) }} 件 — 試算表（ZEAL 経営試算表）の縦軸として使用されます。
    </div>
</div>

<style>
.zeal-drag-handle:hover { color: #047857 !important; background: #ecfdf5 !important; border-color: #6ee7b7 !important; }
.zeal-drag-handle:active { cursor: grabbing !important; background: #d1fae5 !important; }
.zeal-cat-row.zeal-dragging { opacity: 0.4; }
.zeal-cat-row.zeal-drag-over { border-top: 2px solid #10b981 !important; }
</style>

<script>
// Alpine.js: ZEAL 試算表 項目マスターの並び替え
// CLAUDE.md ルール: x-data 内に > を含むアロー関数禁止のため、外部関数として定義
function zealCategoryReorder() {
    return {
        dragId: null,
        reorderMessage: '',
        reorderUrl: '{{ route("admin.master.zeal-simulation-categories.reorder") }}',

        handleDragStart: function (event) {
            var row = event.currentTarget;
            this.dragId = row.getAttribute('data-id');
            row.classList.add('zeal-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', this.dragId);
        },

        handleDragOver: function (event) {
            event.dataTransfer.dropEffect = 'move';
            var row = event.currentTarget;
            // 自分自身にはハイライトを付けない
            if (row.getAttribute('data-id') !== this.dragId) {
                row.classList.add('zeal-drag-over');
            }
        },

        handleDragLeave: function (event) {
            event.currentTarget.classList.remove('zeal-drag-over');
        },

        handleDrop: function (event) {
            var targetRow = event.currentTarget;
            targetRow.classList.remove('zeal-drag-over');

            var draggedId = this.dragId;
            var targetId  = targetRow.getAttribute('data-id');
            if (!draggedId || draggedId === targetId) return;

            // DOM 上で行の入れ替え
            var tbody = targetRow.parentNode;
            var draggedRow = tbody.querySelector('tr[data-id="' + draggedId + '"]');
            if (!draggedRow) return;

            // ターゲットの前後どちらに挿入するか: ドラッグ元のインデックスとターゲットのインデックスを比較
            var draggedIndex = Array.prototype.indexOf.call(tbody.children, draggedRow);
            var targetIndex  = Array.prototype.indexOf.call(tbody.children, targetRow);
            if (draggedIndex < targetIndex) {
                // 下方向への移動: ターゲットの次の兄弟の前に挿入
                tbody.insertBefore(draggedRow, targetRow.nextSibling);
            } else {
                // 上方向への移動: ターゲットの前に挿入
                tbody.insertBefore(draggedRow, targetRow);
            }

            this.saveOrder(tbody);
        },

        handleDragEnd: function (event) {
            var rows = document.querySelectorAll('.zeal-cat-row');
            for (var i = 0; i < rows.length; i++) {
                rows[i].classList.remove('zeal-dragging');
                rows[i].classList.remove('zeal-drag-over');
            }
            this.dragId = null;
        },

        saveOrder: function (tbody) {
            var rows = tbody.querySelectorAll('tr[data-id]');
            var ids = [];
            for (var i = 0; i < rows.length; i++) {
                ids.push(parseInt(rows[i].getAttribute('data-id'), 10));
            }

            var self = this;
            var token = document.querySelector('meta[name="csrf-token"]').content;
            fetch(self.reorderUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ ids: ids }),
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (data.success) {
                        self.reorderMessage = '並び順を更新しました';
                        setTimeout(function () { self.reorderMessage = ''; }, 2500);
                    }
                })
                .catch(function (err) {
                    self.reorderMessage = '';
                    alert('並び替えの保存に失敗しました。ページをリロードしてください。');
                });
        },
    };
}
</script>
@endsection
