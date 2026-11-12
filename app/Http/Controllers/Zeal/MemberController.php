<?php

namespace App\Http\Controllers\Zeal;

use App\Enums\ZealAcquisitionSource;
use App\Enums\ZealContractChangeReason;
use App\Enums\ZealPurpose;
use App\Enums\ZealWithdrawReason;
use App\Http\Controllers\Controller;
use App\Models\ZealMember;
use App\Models\ZealMemberContract;
use App\Models\ZealPlan;
use App\Models\ZealStore;
use App\Models\ZealTrainer;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
        // ⚠ DATE_FORMAT（MySQL 専用）を使わない。テストの SQLite に無く、この一覧をテストで通せなかった。
        //   体験予約一覧（InquiryController）と同じ whereYear / whereMonth。形の崩れた値は 0 件（DATE_FORMAT で比べていたときと同じ）
        if ($request->filled('joined_month')) {
            $joinedMonth = $request->input('joined_month');
            if (is_string($joinedMonth) && preg_match('/^(\d{4})-(\d{2})$/', $joinedMonth, $m)) {
                $query->whereYear('joined_on', (int) $m[1])->whereMonth('joined_on', (int) $m[2]);
            } else {
                $query->whereRaw('1 = 0');
            }
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
        // 入会月セレクト（入会日のある月を新しい順に重複なく。上と同じ理由で月は PHP で作る）
        $joinedMonths = ZealMember::orderByDesc('joined_on')
            ->pluck('joined_on')
            ->map(fn ($joinedOn) => $joinedOn->format('Y-m'))
            ->unique()
            ->values();

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

        // 税率（settings テーブル / 不在時は 10% フォールバック）— 税込計算に Blade / JS で使用
        $taxRate = Settings::taxRate();

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

        $trainers = $this->selectableTrainers($member);
        $stores   = $this->selectableStores($member);

        // 取込で空のまま入ったフリガナ・性別は、この会員の編集では任意（docs/RULES.md Bug #130）
        $kanaRequired   = $this->kanaRequired($member);
        $genderRequired = $this->genderRequired($member);

        return view('zeal.members.edit', compact('member', 'trainers', 'stores', 'kanaRequired', 'genderRequired'));
    }

    /**
     * 会員情報更新
     * Route: PUT /zeal/members/{member}
     */
    public function update(Request $request, ZealMember $member)
    {
        // ⚠ 上限は列の大きさに合わせる（database/sql/create_zeal_tables.sql・本番と一致）。列より広いと本番の MySQL が断って 500。
        //   選択肢の項目はモデルが enum として読むので、選択肢に無い値は保存の前に 500（docs/RULES.md Bug #73）
        // ⚠ 保存されている値は、そのまま保存できるようにする（docs/RULES.md Bug #130）。会員は hacomono の CSV 取込で入り、
        //   取込はフリガナ・性別が空でも・形の崩れたメールでも入れる。ここで断ると、電話番号だけ直したいときでも保存できない
        //   （本番 2026-11-12: 在籍 40 人のうち 17 人）。
        //   - フリガナ・性別: 保存されている値が空のときだけ任意（一度入れると、次からは必須）
        //   - メール: 保存されている値と同じなら形式を問わない（変えたときだけ形式を見る）
        //   - 店舗・担当: 有効なもの ＋ この会員の今の値（無効にしたものでも、今の値なら残す。新しく選べるのは有効なものだけ）
        // ⚠ 規則の値は文字列で組む（Rule::when は CollectsValidationKeys・ShapeCheckingValidator で試していない）
        $emailUnchanged = $request->input('email') === $this->savedText($member->email);
        $validated = $request->validate([
            'store_id'           => ['required', 'integer', Rule::in($this->selectableStores($member)->pluck('id')->all())],
            'name'               => 'required|string|max:100',
            'name_kana'          => ($this->kanaRequired($member) ? 'required' : 'nullable') . '|string|max:100',
            'gender'             => ($this->genderRequired($member) ? 'required' : 'nullable') . '|string|in:male,female,other',
            'birthday'           => 'nullable|date',
            'phone'              => 'nullable|string|max:20',
            'email'              => 'nullable|string|max:100' . ($emailUnchanged ? '' : '|email'),
            'postal_code'        => 'nullable|string|max:8',
            'address'            => 'nullable|string|max:300',
            'trainer_id'         => ['nullable', 'integer', Rule::in($this->selectableTrainers($member)->pluck('id')->all())],
            'acquisition_source' => ['nullable', Rule::enum(ZealAcquisitionSource::class)],
            'purpose'            => ['nullable', Rule::enum(ZealPurpose::class)],
            'memo'               => 'nullable|string|max:1000',
        ], [], [
            // 画面ラベルに合わせる（lang/ja/validation.php の既定は「名称」「住所」）
            'name'    => '氏名',
            'address' => '市区町村・番地以降',
        ]);

        // 未入力の任意項目は null にする
        $validated['birthday']           = $validated['birthday'] ?? null;
        $validated['phone']              = $validated['phone'] ?? null;
        $validated['email']              = $validated['email'] ?? null;
        $validated['postal_code']        = $validated['postal_code'] ?? null;
        $validated['address']            = $validated['address'] ?? null;
        $validated['trainer_id']         = $validated['trainer_id'] ?? null;
        $validated['acquisition_source'] = $validated['acquisition_source'] ?? null;
        $validated['purpose']            = $validated['purpose'] ?? null;
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
        // ⚠ 上限は契約の列に合わせる（applied_price_excl は INT UNSIGNED・note は 200 文字。docs/RULES.md Bug #73）
        $validated = $request->validate([
            'plan_id'              => 'required|exists:zeal_plans,id',
            'change_date'          => 'required|date',
            'applied_price_excl'   => 'required|integer|min:0|max:4294967295',
            'is_campaign_applied'  => 'boolean',
            'note'                 => 'nullable|string|max:200',
        ]);

        $plan = ZealPlan::findOrFail($validated['plan_id']);

        // 現在の税率を取得（settings テーブル / 不在時は 10% フォールバック）
        $taxRate = Settings::taxRate();

        // ⚠ $request も渡す（中で is_campaign_applied を読む）。渡し忘れていた間は、どの値でも 500 だった（docs/RULES.md Bug #74）
        DB::transaction(function () use ($request, $member, $plan, $validated, $taxRate) {
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
            ->with('success', '「' . $member->name . '」のプランを「' . $plan->display_name . '」に変更しました。');
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
            'withdraw_reason' => ['required', Rule::enum(ZealWithdrawReason::class)],   // enum として読む（Bug #73）
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

    // ================================================================
    // 編集で、保存されている値をそのまま保存できるようにする（docs/RULES.md Bug #130）
    // ================================================================

    /**
     * 選べる店舗: 有効な店舗 ＋ この会員の今の店舗（無効にしていても残す。選択肢に無いと、そのまま保存できない）。
     * 新しく選べるのは有効な店舗だけ（Dad\ProjectController::selectableEmployees() と同じ形）
     */
    private function selectableStores(ZealMember $member): Collection
    {
        return ZealStore::query()
            ->where(fn ($q) => $q->where('active', true)->orWhere('id', $member->store_id))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /** 選べるトレーナー: 有効なトレーナー ＋ この会員の今の担当（無効にしていても残す。選択肢に無いと担当が黙って外れる） */
    private function selectableTrainers(ZealMember $member): Collection
    {
        return ZealTrainer::query()
            ->where(fn ($q) => $q->where('active', true)->orWhere('id', $member->trainer_id))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /** フリガナは、保存されている値があるときだけ必須（取込で空のまま入った会員は、空のままでも保存できる） */
    private function kanaRequired(ZealMember $member): bool
    {
        return $this->savedText($member->name_kana) !== null;
    }

    /** 性別は、保存されている値があるときだけ必須 */
    private function genderRequired(ZealMember $member): bool
    {
        return $member->gender !== null;
    }

    /** 保存されている文字を、送られてくる値と同じ形にする（前後の空白を落とし、空なら null。TrimStrings・ConvertEmptyStringsToNull と同じ） */
    private function savedText(?string $value): ?string
    {
        $value = Str::trim((string) $value);

        return $value === '' ? null : $value;
    }
}
