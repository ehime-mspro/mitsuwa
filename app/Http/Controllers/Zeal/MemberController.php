<?php

namespace App\Http\Controllers\Zeal;

use App\Enums\ZealContractChangeReason;
use App\Http\Controllers\Controller;
use App\Models\ZealMember;
use App\Models\ZealMemberContract;
use App\Models\ZealPlan;
use App\Models\ZealTrainer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ZEAL 会員管理コントローラー
 *
 * 会員の一覧・詳細・編集・プラン変更・退会処理・削除を担当する。
 * 新規登録は CSV インポート（ZealMemberImportController）経由のため store/create は実装しない。
 */
class MemberController extends Controller
{
    /**
     * 会員一覧
     * Route: GET /zeal/members
     */
    public function index(Request $request)
    {
        $query = ZealMember::with(['currentPlan', 'trainer'])
            ->orderBy('joined_on', 'desc')
            ->orderBy('id', 'desc');

        // ステータスフィルター（デフォルト: 在籍中）
        $status = $request->input('status', 'active');
        if ($status === 'active') {
            $query->whereNull('withdrew_on');
        } elseif ($status === 'withdrew') {
            $query->whereNotNull('withdrew_on');
        }
        // 'all' の場合はフィルターなし

        // プランフィルター
        if ($request->filled('plan_id')) {
            $query->where('current_plan_id', $request->input('plan_id'));
        }

        // 性別フィルター
        if ($request->filled('gender')) {
            $query->where('gender', $request->input('gender'));
        }

        // 入会月フィルター（YYYY-MM）
        if ($request->filled('joined_month')) {
            $query->whereRaw("DATE_FORMAT(joined_on, '%Y-%m') = ?", [$request->input('joined_month')]);
        }

        // キーワード検索（氏名・フリガナ・電話・メール）
        if ($request->filled('keyword')) {
            $kw = '%' . $request->input('keyword') . '%';
            $query->where(function ($q) use ($kw) {
                $q->where('name', 'like', $kw)
                  ->orWhere('name_kana', 'like', $kw)
                  ->orWhere('phone', 'like', $kw)
                  ->orWhere('email', 'like', $kw);
            });
        }

        $members = $query->paginate(20)->withQueryString();

        // フィルター用データ
        $plans    = ZealPlan::orderBy('display_order')->orderBy('id')->get();
        // 入会月セレクト（過去2年分、月ごとに一意なもの）
        $joinedMonths = ZealMember::selectRaw("DATE_FORMAT(joined_on, '%Y-%m') AS ym")
            ->distinct()
            ->orderBy('ym', 'desc')
            ->pluck('ym');

        return view('zeal.members.index', compact('members', 'plans', 'joinedMonths', 'status'));
    }

    /**
     * 会員詳細
     * Route: GET /zeal/members/{member}
     */
    public function show(ZealMember $member)
    {
        $member->load([
            'currentPlan',
            'trainer',
            'store',
            'gymInquiry',
            'pairParent',
        ]);

        // 契約履歴（現在→古い順）
        $contracts = $member->memberContracts()
            ->with('plan')
            ->orderByRaw('period_end IS NULL DESC')
            ->orderBy('period_start', 'desc')
            ->get();

        // プラン変更モーダル用: アクティブなプランの一覧
        $activePlans = ZealPlan::where('active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        // 退会理由の選択肢
        $withdrawReasons = \App\Enums\ZealWithdrawReason::cases();

        // 税率（settings テーブルから取得）— 税込計算に Blade / JS で使用
        $taxRate = (float) (DB::table('settings')->where('key', 'tax_rate')->value('value') ?? 10);

        return view('zeal.members.show', compact(
            'member', 'contracts', 'activePlans', 'withdrawReasons', 'taxRate'
        ));
    }

    /**
     * 会員編集フォーム
     * Route: GET /zeal/members/{member}/edit
     */
    public function edit(ZealMember $member)
    {
        $member->load(['currentPlan', 'trainer', 'store']);

        $trainers = ZealTrainer::where('active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return view('zeal.members.edit', compact('member', 'trainers'));
    }

    /**
     * 会員情報更新
     * Route: PUT /zeal/members/{member}
     */
    public function update(Request $request, ZealMember $member)
    {
        $validated = $request->validate([
            'name'               => 'required|string|max:50',
            'name_kana'          => 'required|string|max:100',
            'gender'             => 'required|string|in:male,female,other',
            'birthday'           => 'nullable|date',
            'phone'              => 'nullable|string|max:20',
            'email'              => 'nullable|email|max:200',
            'postal_code'        => 'nullable|string|max:8',
            'address'            => 'nullable|string|max:200',
            'trainer_id'         => 'nullable|exists:zeal_trainers,id',
            'acquisition_source' => 'nullable|string',
            'purpose'            => 'nullable|string',
            'memo'               => 'nullable|string|max:1000',
        ]);

        // 未入力の任意項目は null にする
        $validated['birthday']           = $validated['birthday'] ?? null;
        $validated['phone']              = $validated['phone'] ?? null;
        $validated['email']              = $validated['email'] ?? null;
        $validated['postal_code']        = $validated['postal_code'] ?? null;
        $validated['address']            = $validated['address'] ?? null;
        $validated['trainer_id']         = $validated['trainer_id'] ?? null;
        $validated['acquisition_source'] = $validated['acquisition_source'] ?: null;
        $validated['purpose']            = $validated['purpose'] ?: null;
        $validated['memo']               = $validated['memo'] ?? null;
        $validated['updated_by']         = auth()->id();

        $member->update($validated);

        return redirect()
            ->route('zeal.members.show', $member)
            ->with('success', '「' . $member->name . '」の情報を更新しました。');
    }

    /**
     * プラン変更処理（Ajax POST）
     * Route: POST /zeal/members/{member}/change-plan
     *
     * SCD Type-2 パターン:
     *   1. 現行契約の period_end を (変更日 - 1日) に更新
     *   2. 新しい契約レコードを INSERT
     *   3. zeal_members.current_plan_id を更新
     */
    public function changePlan(Request $request, ZealMember $member)
    {
        $validated = $request->validate([
            'plan_id'              => 'required|exists:zeal_plans,id',
            'change_date'          => 'required|date',
            'applied_price_excl'   => 'required|integer|min:0',
            'is_campaign_applied'  => 'boolean',
            'note'                 => 'nullable|string|max:500',
        ]);

        $plan = ZealPlan::findOrFail($validated['plan_id']);

        // 現在の税率を取得（settings テーブル）
        $taxRate = (float) (DB::table('settings')->where('key', 'tax_rate')->value('value') ?? 10);

        DB::transaction(function () use ($member, $plan, $validated, $taxRate) {
            $changeDate = Carbon::parse($validated['change_date']);

            // 1. 現行契約を締結（period_end = 変更日の前日）
            ZealMemberContract::where('member_id', $member->id)
                ->whereNull('period_end')
                ->update([
                    'period_end' => $changeDate->copy()->subDay()->toDateString(),
                ]);

            // 2. 新しい契約レコードを追加
            ZealMemberContract::create([
                'member_id'            => $member->id,
                'plan_id'              => $plan->id,
                'period_start'         => $changeDate->toDateString(),
                'period_end'           => null,
                'applied_price_excl'   => $validated['applied_price_excl'],
                'is_campaign_applied'  => $request->boolean('is_campaign_applied', false),
                'tax_rate_at_contract' => $taxRate,
                'change_reason'        => ZealContractChangeReason::PlanChange->value,
                'note'                 => $validated['note'] ?? null,
                'created_by'           => auth()->id(),
            ]);

            // 3. キャッシュカラムを同期
            $member->update(['current_plan_id' => $plan->id]);
        });

        return redirect()
            ->route('zeal.members.show', $member)
            ->with('success', '「' . $member->name . '」のプランを「' . $plan->name . '」に変更しました。');
    }

    /**
     * 退会処理（POST）
     * Route: POST /zeal/members/{member}/withdraw
     *
     * SCD Type-2 パターン:
     *   1. 現行契約を退会理由で締結
     *   2. zeal_members.withdrew_on / withdraw_reason / current_plan_id = NULL を更新
     */
    public function withdraw(Request $request, ZealMember $member)
    {
        if ($member->withdrew_on !== null) {
            return back()->with('error', 'この会員はすでに退会済みです。');
        }

        $validated = $request->validate([
            'withdrew_on'     => 'required|date',
            'withdraw_reason' => 'required|string',
            'withdraw_note'   => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($member, $validated) {
            $withdrawDate = Carbon::parse($validated['withdrew_on']);

            // 1. 現行契約を退会理由で締結
            ZealMemberContract::where('member_id', $member->id)
                ->whereNull('period_end')
                ->update([
                    'period_end'    => $withdrawDate->toDateString(),
                    'change_reason' => ZealContractChangeReason::Withdraw->value,
                ]);

            // 2. 会員レコードを退会状態に更新
            $member->update([
                'withdrew_on'     => $withdrawDate->toDateString(),
                'withdraw_reason' => $validated['withdraw_reason'],
                'withdraw_note'   => $validated['withdraw_note'] ?? null,
                'current_plan_id' => null,
                'updated_by'      => auth()->id(),
            ]);
        });

        return redirect()
            ->route('zeal.members.show', $member)
            ->with('success', '「' . $member->name . '」の退会処理を完了しました。');
    }

    /**
     * 会員削除（経営層のみ）
     * Route: DELETE /zeal/members/{member}
     */
    public function destroy(ZealMember $member)
    {
        $name = $member->name;

        // 関連する契約履歴を先に削除
        $member->memberContracts()->delete();
        $member->delete();

        return redirect()
            ->route('zeal.members.index')
            ->with('success', '「' . $name . '」を削除しました。');
    }
}
