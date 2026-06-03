<?php

namespace App\Http\Controllers;

use App\Enums\BuyerDepartment;
use App\Enums\BuyerRank;
use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * URLプレフィックスから部署を判定
     */
    private function resolveDepartment(): string
    {
        $segment = request()->segment(1);
        if (in_array($segment, ['housing', 'realestate'])) {
            return $segment;
        }
        abort(404);
    }

    /**
     * 顧客一覧
     */
    public function index(Request $request)
    {
        $department = $this->resolveDepartment();
        $rankFilter = $request->input('rank', 'active');
        $keyword    = $request->input('keyword');

        $query = Buyer::ofDepartment($department)
            ->with(['departments' => function ($q) use ($department) {
                $q->where('department', $department);
            }]);

        // ランクフィルター
        if ($rankFilter === 'active') {
            $activeRanks = array_map(function ($r) { return $r->value; }, BuyerRank::activeRanks());
            $query->ofRank($department, $activeRanks);
        } elseif ($rankFilter !== 'all') {
            $query->ofRank($department, $rankFilter);
        }

        // キーワード検索
        $query->keywordSearch($keyword);

        // 取得日降順ソート（buyer_departments経由）
        $query->join('buyer_departments as bd', function ($join) use ($department) {
            $join->on('bd.buyer_id', '=', 'buyers.id')
                 ->where('bd.department', '=', $department);
        })
        ->select('buyers.*')
        ->orderBy('bd.acquired_date', 'desc');

        $buyers = $query->paginate(20)->appends($request->query());

        // 住宅事業の場合、最新アンケートの分譲地名を取得
        $projectNames = [];
        if ($department === 'housing') {
            $buyerIds = $buyers->pluck('id')->toArray();
            if ($buyerIds) {
                $latestSurveys = BuyerSurvey::whereIn('buyer_id', $buyerIds)
                    ->where('department', 'housing')
                    ->whereNotNull('project_id')
                    ->with('project')
                    ->orderBy('survey_date', 'desc')
                    ->get()
                    ->unique('buyer_id');
                foreach ($latestSurveys as $survey) {
                    if ($survey->project) {
                        $projectNames[$survey->buyer_id] = $survey->project->project_name;
                    }
                }
            }
        }

        $deptLabel = BuyerDepartment::from($department)->label();

        return view('buyers.index', compact(
            'department', 'deptLabel', 'buyers', 'rankFilter', 'keyword', 'projectNames'
        ));
    }

    /**
     * 顧客登録画面
     */
    public function create(Request $request)
    {
        $department = $this->resolveDepartment();
        $deptLabel  = BuyerDepartment::from($department)->label();

        // 当該部署の有効設問
        $questions = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();

        // 住宅事業: 分譲地リスト、担当者リスト
        $projects = [];
        $staffList = [];
        if ($department === 'housing') {
            $projects = DB::table('re_projects')->orderBy('project_name')->pluck('project_name', 'id')->toArray();
            $staffList = $this->getStaffList();
        }

        // 都道府県リスト
        $prefectures = $this->getPrefectures();

        return view('buyers.create', compact(
            'department', 'deptLabel', 'questions', 'projects', 'staffList', 'prefectures'
        ));
    }

    /**
     * 顧客登録保存
     */
    public function store(Request $request)
    {
        $department = $this->resolveDepartment();

        $request->validate([
            'last_name'     => 'required|max:50',
            'first_name'    => 'required|max:50',
            'acquired_date' => 'required|date',
        ]);

        DB::beginTransaction();
        try {
            // buyers テーブル
            $buyer = Buyer::create($request->only([
                'last_name', 'first_name', 'last_name_kana', 'first_name_kana',
                'birth_date', 'birth_era', 'family_adults', 'family_children',
                'postal_code', 'prefecture', 'city', 'address_detail', 'building_name',
                'phone', 'email', 'occupation', 'employer', 'years_employed', 'memo',
            ]));

            // buyer_departments ピボット
            $buyer->addToDepartment($department, $request->input('acquired_date'));

            // アンケート保存（設問がある場合）
            $this->saveSurvey($request, $buyer, $department);

            DB::commit();

            return redirect()
                ->route("{$department}.customers.show", $buyer)
                ->with('success', '顧客を登録しました。');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', '登録に失敗しました: ' . $e->getMessage());
        }
    }

    /**
     * 顧客詳細
     */
    public function show(Request $request, Buyer $buyer)
    {
        $department = $this->resolveDepartment();

        $buyer->load(['departments', 'surveys' => function ($q) use ($department) {
            $q->ofDepartment($department)->orderBy('survey_date', 'desc');
        }, 'surveys.project']);

        $deptLabel = BuyerDepartment::from($department)->label();
        $pivot     = $buyer->getDepartmentPivot($department);

        // 担当者表示用の名前マップ
        $staffNames = $this->resolveStaffDisplayNames(
            $buyer->surveys->pluck('staff_name')->filter()->unique()->toArray()
        );

        return view('buyers.show', compact(
            'department', 'deptLabel', 'buyer', 'pivot', 'staffNames'
        ));
    }

    /**
     * 顧客編集画面
     */
    public function edit(Request $request, Buyer $buyer)
    {
        $department = $this->resolveDepartment();
        $deptLabel  = BuyerDepartment::from($department)->label();
        $pivot      = $buyer->getDepartmentPivot($department);
        $prefectures = $this->getPrefectures();

        return view('buyers.edit', compact(
            'department', 'deptLabel', 'buyer', 'pivot', 'prefectures'
        ));
    }

    /**
     * 顧客更新
     */
    public function update(Request $request, Buyer $buyer)
    {
        $department = $this->resolveDepartment();

        $request->validate([
            'last_name'     => 'required|max:50',
            'first_name'    => 'required|max:50',
            'acquired_date' => 'required|date',
        ]);

        DB::beginTransaction();
        try {
            $buyer->update($request->only([
                'last_name', 'first_name', 'last_name_kana', 'first_name_kana',
                'birth_date', 'birth_era', 'family_adults', 'family_children',
                'postal_code', 'prefecture', 'city', 'address_detail', 'building_name',
                'phone', 'email', 'occupation', 'employer', 'years_employed', 'memo',
            ]));

            // ピボット更新（取得日）
            $pivot = $buyer->getDepartmentPivot($department);
            if ($pivot) {
                $pivot->update(['acquired_date' => $request->input('acquired_date')]);
            }

            DB::commit();

            return redirect()
                ->route("{$department}.customers.show", $buyer)
                ->with('success', '顧客情報を更新しました。');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', '更新に失敗しました: ' . $e->getMessage());
        }
    }

    /**
     * 顧客削除（ソフトデリート）
     */
    public function destroy(Request $request, Buyer $buyer)
    {
        $department = $this->resolveDepartment();
        $buyer->delete();

        return redirect()
            ->route("{$department}.customers.index")
            ->with('success', '顧客を削除しました。');
    }

    /**
     * ランク即時変更（Ajax）
     */
    public function updateRank(Request $request, Buyer $buyer)
    {
        $request->validate([
            'rank'       => 'required|in:A,B,C,D,lost,unreachable,contracted',
            'department' => 'required|in:housing,realestate',
        ]);

        $pivot = $buyer->getDepartmentPivot($request->input('department'));
        if (!$pivot) {
            return response()->json(['error' => '部署情報が見つかりません'], 404);
        }

        $pivot->update(['rank' => $request->input('rank')]);
        $newRank = BuyerRank::from($request->input('rank'));

        return response()->json([
            'success'    => true,
            'rank'       => $newRank->value,
            'label'      => $newRank->label(),
            'badgeStyle' => $newRank->badgeStyle(),
        ]);
    }

    /**
     * 重複チェック（Ajax）
     */
    public function checkDuplicate(Request $request)
    {
        $lastName   = $request->input('last_name');
        $firstName  = $request->input('first_name');
        $prefecture = $request->input('prefecture');
        $city       = $request->input('city');
        $currentDept = $request->input('department');
        $excludeId  = $request->input('exclude_id');

        if (!$lastName || !$firstName) {
            return response()->json(['duplicates' => []]);
        }

        $query = Buyer::where('last_name', $lastName)
            ->where('first_name', $firstName);

        if ($prefecture) {
            $query->where('prefecture', $prefecture);
        }
        if ($city) {
            $query->where('city', $city);
        }
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $duplicates = $query->with('departments')->get()->map(function ($buyer) use ($currentDept) {
            $depts = $buyer->departments->pluck('department')->toArray();
            $sameDept  = in_array($currentDept, $depts);
            $otherDept = array_diff($depts, [$currentDept]);

            return [
                'id'         => $buyer->id,
                'full_name'  => $buyer->full_name,
                'address'    => ($buyer->prefecture ?? '') . ($buyer->city ?? ''),
                'same_dept'  => $sameDept,
                'other_dept' => array_values($otherDept),
            ];
        });

        return response()->json(['duplicates' => $duplicates]);
    }

    /**
     * 他部署追加（Ajax）
     */
    public function addToDepartment(Request $request, Buyer $buyer)
    {
        $request->validate([
            'department'    => 'required|in:housing,realestate',
            'acquired_date' => 'required|date',
        ]);

        $dept = $request->input('department');

        if ($buyer->belongsToDepartment($dept)) {
            return response()->json(['error' => 'すでにこの部署に登録されています'], 422);
        }

        $buyer->addToDepartment($dept, $request->input('acquired_date'));

        return response()->json([
            'success'  => true,
            'redirect' => route("{$dept}.customers.show", $buyer),
        ]);
    }

    /**
     * 住所→郵便番号 逆引き（Ajax）
     * HeartRails GeoAPI の getTowns メソッドを使用
     * 市区町村欄に町名まで含まれている場合でも正しく処理
     */
    public function reverseZipLookup(Request $request)
    {
        $prefecture = $request->input('prefecture', '');
        $cityRaw    = $request->input('city', '');

        if (!$prefecture || !$cityRaw) {
            return response()->json(['error' => '都道府県と市区町村を入力してください'], 422);
        }

        // 市区町村名と町域を分離
        // 例: "松山市勝山町2-4-7" → city="松山市", town="勝山町2-4-7"
        // 例: "松山市" → city="松山市", town=""
        // 例: "北九州市小倉北区" → city="北九州市小倉北区", town=""
        $city = $cityRaw;
        $town = '';

        // 政令指定都市パターン: ○○市○○区（以降は町域）
        if (preg_match('/^(.+市.+区)(.*)$/u', $cityRaw, $m)) {
            $city = $m[1];
            $town = $m[2];
        }
        // 通常の市: ○○市（以降は町域）
        elseif (preg_match('/^(.+市)(.+)$/u', $cityRaw, $m)) {
            $city = $m[1];
            $town = $m[2];
        }
        // 郡+町: ○○郡○○町（以降は町域）
        elseif (preg_match('/^(.+郡.+町)(.*)$/u', $cityRaw, $m)) {
            $city = $m[1];
            $town = $m[2];
        }
        // 郡+村: ○○郡○○村（以降は町域）
        elseif (preg_match('/^(.+郡.+村)(.*)$/u', $cityRaw, $m)) {
            $city = $m[1];
            $town = $m[2];
        }
        // 東京23区: ○○区（以降は町域）
        elseif (preg_match('/^(.+区)(.+)$/u', $cityRaw, $m)) {
            $city = $m[1];
            $town = $m[2];
        }

        // 町域部分から数字（番地）を除去して町名のみに
        $townName = preg_replace('/[\d\-０-９ー]+.*$/u', '', $town);

        try {
            $url = 'https://geoapi.heartrails.com/api/json?method=getTowns'
                 . '&prefecture=' . urlencode($prefecture)
                 . '&city=' . urlencode($city);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            // TLS 証明書を検証する（MITM 対策）。HeartRails GeoAPI は正規証明書を持つため検証を有効化。
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $response = curl_exec($ch);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error || !$response) {
                return response()->json(['error' => '外部API通信に失敗しました'], 500);
            }

            $data = json_decode($response, true);

            if (!isset($data['response']['location']) || count($data['response']['location']) === 0) {
                return response()->json(['error' => '該当する郵便番号が見つかりませんでした'], 404);
            }

            $locations = $data['response']['location'];

            // 町名が指定されている場合、一致する町域を探す
            if ($townName) {
                foreach ($locations as $loc) {
                    $locTown = $loc['town'] ?? '';
                    if (mb_strpos($locTown, $townName) === 0 || mb_strpos($townName, $locTown) === 0) {
                        $postal = preg_replace('/[^0-9]/', '', $loc['postal'] ?? '');
                        if (strlen($postal) === 7) {
                            return response()->json([
                                'postal_code' => substr($postal, 0, 3) . '-' . substr($postal, 3),
                            ]);
                        }
                    }
                }
            }

            // 町名一致なし or 町名未指定 → 最初の町域の郵便番号を返す
            $postal = preg_replace('/[^0-9]/', '', $locations[0]['postal'] ?? '');
            if (strlen($postal) === 7) {
                return response()->json([
                    'postal_code' => substr($postal, 0, 3) . '-' . substr($postal, 3),
                ]);
            }

            return response()->json(['error' => '該当する郵便番号が見つかりませんでした'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => '検索に失敗しました'], 500);
        }
    }

    /* ========== Private メソッド ========== */

    /**
     * アンケート保存（顧客登録時の初回アンケート）
     */
    private function saveSurvey(Request $request, Buyer $buyer, string $department): void
    {
        $questions = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();
        if ($questions->isEmpty()) {
            return;
        }

        // アンケート回答が1つでもあるか確認
        $hasAnswer = false;
        foreach ($questions as $q) {
            if ($request->filled("survey.{$q->id}")) {
                $hasAnswer = true;
                break;
            }
        }
        if (!$hasAnswer) {
            return;
        }

        // ヘッダ作成
        $surveyData = [
            'buyer_id'    => $buyer->id,
            'department'  => $department,
            'survey_date' => $request->input('acquired_date'),
        ];

        if ($department === 'housing') {
            $projectId = $request->input('project_id');
            if ($projectId) {
                $surveyData['project_id'] = $projectId;
            }
            $staffUserId = $request->input('staff_user_id');
            if ($staffUserId) {
                $surveyData['staff_user_id'] = $staffUserId;
                $staffUser = User::find($staffUserId);
                if ($staffUser) {
                    $surveyData['staff_name'] = $staffUser->name;
                }
            }
        }

        $survey = BuyerSurvey::create($surveyData);

        // 回答明細
        foreach ($questions as $q) {
            $rawValue = $request->input("survey.{$q->id}");
            if ($rawValue === null || $rawValue === '' || $rawValue === []) {
                continue;
            }

            $answerValue = $this->normalizeAnswerValue($rawValue, $q->getRawOriginal('question_type'));

            $survey->answers()->create([
                'question_id'       => $q->id,
                'answer_value'      => $answerValue,
                'question_snapshot' => $q->toSnapshot(),
            ]);
        }
    }

    /**
     * 回答値をquestion_typeに応じて正規化
     */
    private function normalizeAnswerValue($rawValue, string $questionType): string
    {
        if (!is_array($rawValue)) {
            return (string) $rawValue;
        }

        if ($questionType === 'multi_select') {
            return json_encode(array_values($rawValue), JSON_UNESCAPED_UNICODE);
        }

        if ($questionType === 'select_with_text') {
            $items = [];
            foreach ($rawValue as $entry) {
                if (is_array($entry) && isset($entry['value']) && $entry['value'] !== '') {
                    $item = ['value' => $entry['value']];
                    if (isset($entry['text']) && $entry['text'] !== '') {
                        $item['text'] = $entry['text'];
                    }
                    $items[] = $item;
                }
            }
            return json_encode($items, JSON_UNESCAPED_UNICODE);
        }

        if ($questionType === 'conditional_select') {
            return json_encode($rawValue, JSON_UNESCAPED_UNICODE);
        }

        return json_encode($rawValue, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 担当者リスト（苗字のみ、同姓時フルネーム）
     */
    private function getStaffList(): array
    {
        $users = User::orderBy('name')
            ->get(['id', 'name']);

        $lastNames = [];
        foreach ($users as $u) {
            $parts = preg_split('/[\s　]+/', $u->name);
            $ln = $parts[0] ?? $u->name;
            if (!isset($lastNames[$ln])) {
                $lastNames[$ln] = 0;
            }
            $lastNames[$ln]++;
        }

        $result = [];
        foreach ($users as $u) {
            $parts = preg_split('/[\s　]+/', $u->name);
            $ln = $parts[0] ?? $u->name;
            $displayName = ($lastNames[$ln] >= 2) ? $u->name : $ln;
            $result[$u->id] = $displayName;
        }
        return $result;
    }

    /**
     * 担当者名テキストの表示名解決
     */
    private function resolveStaffDisplayNames(array $staffNames): array
    {
        $lastNames = [];
        foreach ($staffNames as $name) {
            $parts = preg_split('/[\s　]+/', $name);
            $ln = $parts[0] ?? $name;
            if (!isset($lastNames[$ln])) {
                $lastNames[$ln] = [];
            }
            $lastNames[$ln][] = $name;
        }

        $result = [];
        foreach ($staffNames as $name) {
            $parts = preg_split('/[\s　]+/', $name);
            $ln = $parts[0] ?? $name;
            $result[$name] = (count($lastNames[$ln]) >= 2) ? $name : $ln;
        }
        return $result;
    }

    /**
     * 都道府県リスト
     */
    private function getPrefectures(): array
    {
        return [
            '北海道','青森県','岩手県','宮城県','秋田県','山形県','福島県',
            '茨城県','栃木県','群馬県','埼玉県','千葉県','東京都','神奈川県',
            '新潟県','富山県','石川県','福井県','山梨県','長野県','岐阜県',
            '静岡県','愛知県','三重県','滋賀県','京都府','大阪府','兵庫県',
            '奈良県','和歌山県','鳥取県','島根県','岡山県','広島県','山口県',
            '徳島県','香川県','愛媛県','高知県','福岡県','佐賀県','長崎県',
            '熊本県','大分県','宮崎県','鹿児島県','沖縄県',
        ];
    }
}
