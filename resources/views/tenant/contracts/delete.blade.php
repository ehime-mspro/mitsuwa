@extends('layouts.app')

@section('title', '契約削除: ' . $contract->contract_number)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.contracts.index') }}" class="hover:text-emerald-600 transition-colors">契約一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.contracts.show', $contract) }}" class="hover:text-emerald-600 transition-colors">{{ $contract->contract_number }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">削除</span>
@endsection

@section('content')

    {{-- 戻るリンク --}}
    <a href="{{ route('tenant.contracts.show', $contract) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        契約詳細に戻る
    </a>

    {{-- ページタイトル --}}
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">契約削除: {{ $contract->contract_number }}</h1>

    {{-- 警告 --}}
    <div class="flex items-start gap-2 mb-4 rounded-lg border border-red-200 bg-red-50 p-3.5">
        <svg class="w-5 h-5 text-red-600 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <div class="text-sm text-red-800 leading-relaxed">
            <strong>この契約を削除します。削除すると以下が実行されます:</strong><br>
            ・契約は論理削除され、一覧・詳細から見えなくなります（データはDBに残ります。復元が必要な場合は管理者に連絡してください）。
            @if($contract->isActive())
                <br>・契約中のため、区画「{{ $contract->unit?->display_name ?? '—' }}」は<strong>空室に戻ります</strong>。
            @endif
            <br>・紐づく問合せは<strong>未成約（フォロー）に差し戻され</strong>ます。
            <br>・紐づく投資案件は区画に残り、この契約との紐付けのみ解除されます。
        </div>
    </div>

    {{-- 対象契約情報 --}}
    @php
        $monthlyTotal = $contract->rent + ($contract->common_fee ?? 0) + ($contract->garbage_fee ?? 0) + ($contract->pest_control_fee ?? 0);
        $unit = $contract->unit;
        $dn = $unit?->display_name ?? '';
        $unitLabel = ($unit && $unit->floor !== null && !preg_match('/^\d/', $dn)) ? $unit->floor . $dn : $dn;
    @endphp
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">対象契約</div>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">契約番号</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->contract_number }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">ステータス</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->status->label() }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">物件 / 区画</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->property?->name ?? '（物件データなし）' }} / {{ $unitLabel !== '' ? $unitLabel : '（区画データなし）' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">テナント</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->customer?->name ?? $contract->store_name ?? '（顧客データなし）' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">月額家賃</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($contract->rent) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">月額合計</div>
                <div class="text-sm font-bold" style="color:#065F46;">{{ number_format($monthlyTotal) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span></div>
            </div>
        </div>
    </div>

    {{-- 関連データ件数（confirmDelete からスカラーで受ける） --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">この契約に紐づくデータ</div>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">投資案件</div>
                <div class="text-sm font-medium text-gray-900">{{ $hasInvestment ? 'あり（紐付け解除）' : 'なし' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">賃料改定履歴</div>
                <div class="text-sm font-medium text-gray-900">{{ $rentRevisionCount }}件</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">紐づく問合せ</div>
                <div class="text-sm font-medium text-gray-900">{{ $relatedInquiryCount }}件</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">添付ファイル</div>
                <div class="text-sm font-medium text-gray-900">{{ $attachmentCount }}件</div>
            </div>
        </div>
    </div>

    {{-- アクションボタン --}}
    <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2">
        <a href="{{ route('tenant.contracts.show', $contract) }}"
           class="px-4 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-md text-sm text-center hover:bg-gray-50 transition-colors">
            キャンセル
        </a>
        <form method="POST" action="{{ route('tenant.contracts.destroy', $contract) }}"
              onsubmit="return confirm('本当にこの契約を削除しますか？この操作は元に戻せません。');">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="inline-flex items-center justify-center gap-1.5 px-5 py-2.5 bg-red-600 text-white rounded-md text-sm font-semibold hover:bg-red-700 transition-colors cursor-pointer">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                削除する
            </button>
        </form>
    </div>

@endsection
