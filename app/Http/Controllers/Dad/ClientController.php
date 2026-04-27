<?php

namespace App\Http\Controllers\Dad;

use App\Enums\DadClientType;
use App\Http\Controllers\Controller;
use App\Models\DadClient;
use Illuminate\Http\Request;

/**
 * DAD 発注者管理コントローラー
 * 公共事業・推進関連の発注者をマスター管理する
 */
class ClientController extends Controller
{
    /**
     * 一覧表示（種別フィルター + 名称検索）
     */
    public function index(Request $request)
    {
        $query = DadClient::query()->withCount('projects');

        if ($request->filled('client_type')) {
            $query->where('client_type', $request->input('client_type'));
        }

        if ($request->filled('keyword')) {
            $kw = trim((string) $request->input('keyword'));
            $query->where(function ($q) use ($kw) {
                $q->where('name', 'like', '%' . $kw . '%')
                  ->orWhere('representative', 'like', '%' . $kw . '%');
            });
        }

        $clients = $query->orderBy('client_type')->orderBy('name')->paginate(20)->withQueryString();

        // 集計（フィルター無視で全件カウント）
        $countMunicipality = DadClient::where('client_type', DadClientType::Municipality->value)->count();
        $countCompany = DadClient::where('client_type', DadClientType::Company->value)->count();

        return view('dad.clients.index', compact('clients', 'countMunicipality', 'countCompany'));
    }

    /**
     * 新規登録フォーム
     */
    public function create()
    {
        return view('dad.clients.create');
    }

    /**
     * 新規登録処理
     */
    public function store(Request $request)
    {
        $validated = $this->validateClient($request);
        $validated['created_by'] = $request->user()->id;

        $client = DadClient::create($validated);

        return redirect()
            ->route('dad.clients.index')
            ->with('success', '「' . $client->name . '」を登録しました。');
    }

    /**
     * 編集フォーム
     */
    public function edit(DadClient $client)
    {
        return view('dad.clients.edit', compact('client'));
    }

    /**
     * 更新処理
     */
    public function update(Request $request, DadClient $client)
    {
        $validated = $this->validateClient($request);
        $client->update($validated);

        return redirect()
            ->route('dad.clients.index')
            ->with('success', '「' . $client->name . '」を更新しました。');
    }

    /**
     * 削除処理（工事案件で参照中は不可）
     */
    public function destroy(DadClient $client)
    {
        if ($client->hasProjects()) {
            return redirect()
                ->route('dad.clients.index')
                ->with('error', '「' . $client->name . '」は工事案件で使用中のため削除できません。');
        }

        $name = $client->name;
        $client->delete();

        return redirect()
            ->route('dad.clients.index')
            ->with('success', '「' . $name . '」を削除しました。');
    }

    /**
     * バリデーションを共通化
     */
    private function validateClient(Request $request): array
    {
        return $request->validate([
            'client_type' => ['required', 'in:municipality,company'],
            'name' => ['required', 'string', 'max:100'],
            'representative' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:20'],
            'fax' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
        ], [
            'client_type.required' => '種別を選択してください。',
            'client_type.in' => '種別の値が不正です。',
            'name.required' => '発注者名は必須です。',
            'name.max' => '発注者名は100文字以内で入力してください。',
            'email.email' => 'メールアドレスの形式が正しくありません。',
        ]);
    }
}
