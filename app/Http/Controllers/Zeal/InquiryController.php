<?php

namespace App\Http\Controllers\Zeal;

use App\Enums\ZealGymInquiryStatus;
use App\Http\Controllers\Controller;
use App\Models\GymInquiry;
use App\Models\ZealMember;
use Illuminate\Http\Request;

/**
 * ZEAL 体験予約閲覧コントローラー
 *
 * 外部 DB（mitsuwa-ud_zeel-b）の gym_inquiries を参照のみ。
 * 書き込み操作（登録・更新・削除）は一切行わない。
 */
class InquiryController extends Controller
{
    /**
     * 体験予約一覧
     */
    public function index(Request $request)
    {
        $query = GymInquiry::query();

        // フィルター: ステータス
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // フィルター: 月（inquiry_date の年月）
        if ($request->filled('month')) {
            [$year, $month] = explode('-', $request->month);
            $query->whereYear('inquiry_date', $year)
                  ->whereMonth('inquiry_date', $month);
        }

        // フィルター: キーワード（氏名部分一致）
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where('name', 'like', "%{$keyword}%");
        }

        $inquiries = $query->orderByDesc('inquiry_date')
                           ->orderByDesc('id')
                           ->paginate(20)
                           ->withQueryString();

        // ステータス選択肢
        $statuses = ZealGymInquiryStatus::cases();

        // 月選択肢（過去18か月分）
        $months = collect();
        for ($i = 0; $i < 18; $i++) {
            $months->push(now()->subMonths($i)->format('Y-m'));
        }

        return view('zeal.inquiries.index', compact('inquiries', 'statuses', 'months'));
    }

    /**
     * 体験予約詳細
     *
     * GymInquiry は外部 DB のため、ルートモデルバインディングは使わず find() を利用
     */
    public function show(int $id)
    {
        $inquiry = GymInquiry::findOrFail($id);

        // 同じ体験予約から入会した会員（gym_inquiry_id が一致するもの）
        $member = ZealMember::where('gym_inquiry_id', $id)->first();

        return view('zeal.inquiries.show', compact('inquiry', 'member'));
    }
}
