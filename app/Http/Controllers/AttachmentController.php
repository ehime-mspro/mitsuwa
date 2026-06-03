<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Contract;
use App\Models\DadProject;
use App\Models\Investment;
use App\Models\MsTenant;
use App\Models\Repair;
use App\Models\ReProject;
use App\Models\ReProcurement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    /**
     * タイプからモデルクラスへのマッピング
     * ms_tenants は賃貸マンション管理の入居申込書（Phase E で追加）。
     * ms_contracts は Phase F で追加予定。
     */
    private const TYPE_MAP = [
        'contracts'    => Contract::class,
        'investments'  => Investment::class,
        'repairs'      => Repair::class,
        'procurements' => ReProcurement::class,
        'projects'     => ReProject::class,
        'ms_tenants'   => MsTenant::class,
        'dad_projects' => DadProject::class,
    ];

    /**
     * morph クラス → 部署コードのマッピング（TYPE_MAP と対になる）。
     * 添付の親リソースが属する部署を判定し、IDOR（他部署の添付閲覧/操作）を防ぐ。
     */
    private const DEPARTMENT_MAP = [
        Contract::class      => 'tenant',
        Investment::class    => 'tenant',
        Repair::class        => 'tenant',
        ReProcurement::class => 'realestate',
        ReProject::class     => 'realestate',
        MsTenant::class      => 'mansion',
        DadProject::class    => 'dad',
    ];

    /**
     * ファイルアップロード（Ajax）
     * POST /attachments/{type}/{id}
     */
    public function store(Request $request, string $type, int $id)
    {
        // タイプの検証
        if (! isset(self::TYPE_MAP[$type])) {
            return response()->json(['success' => false, 'message' => '不正なタイプです。'], 400);
        }

        // モデルの取得
        $modelClass = self::TYPE_MAP[$type];
        $model = $modelClass::find($id);
        if (! $model) {
            return response()->json(['success' => false, 'message' => '対象が見つかりません。'], 404);
        }

        // 部署ベースの認可（IDOR 対策）: 他部署の案件に添付できないようにする
        if (! $this->canAccessDepartmentOf($modelClass)) {
            return response()->json(['success' => false, 'message' => 'この対象へのアクセス権限がありません。'], 403);
        }

        // ファイルの検証
        $request->validate([
            'files'   => 'required|array|min:1',
            'files.*' => 'required|file|max:10240|mimes:jpg,jpeg,png,gif,webp,heic,heif,pdf,doc,docx,xls,xlsx,csv,txt',
        ]);

        $uploaded = [];

        foreach ($request->file('files') as $file) {
            $path = $file->store('attachments/' . $type . '/' . $id, 'public');

            $attachment = $model->attachments()->create([
                'file_name'   => $file->getClientOriginalName(),
                'file_path'   => $path,
                'file_size'   => $file->getSize(),
                'mime_type'   => $file->getMimeType(),
                'uploaded_by' => Auth::id(),
            ]);

            $attachment->load('uploadedByUser');

            $uploaded[] = [
                'id'          => $attachment->id,
                'file_name'   => $attachment->file_name,
                'file_path'   => route('attachments.show', $attachment->id),
                'file_size'   => $attachment->file_size_formatted,
                'uploaded_by' => $attachment->uploadedByUser->name ?? '—',
                'uploaded_at' => $attachment->created_at->format('Y/m/d H:i'),
                'can_delete'  => true,
            ];
        }

        return response()->json([
            'success'     => true,
            'attachments' => $uploaded,
            'message'     => count($uploaded) . '件のファイルをアップロードしました。',
        ]);
    }

    /**
     * ファイル表示・ダウンロード
     * GET /attachments/{attachment}
     *
     * 本番のディレクトリ構造（アプリ本体と Web 公開ディレクトリが別パス）では
     * public/storage シンボリックリンクが壊れるため、Apache 直配信ではなく
     * Laravel 経由で storage/app/public からストリーミング配信する。
     */
    public function show(Attachment $attachment)
    {
        // 部署ベースの認可（IDOR 対策）: 連番 ID 総当たりでの他部署添付の閲覧を防ぐ。
        // ファイル存在チェックより前に評価する。
        abort_unless($this->canAccessDepartmentOf($attachment->attachable_type), 403);

        if (! Storage::disk('public')->exists($attachment->file_path)) {
            abort(404);
        }

        // 保存型 XSS 対策: inline ではなく強制ダウンロードで配信し、
        // X-Content-Type-Options: nosniff で MIME スニッフィングによる実行を防ぐ。
        return Storage::disk('public')->download($attachment->file_path, $attachment->file_name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * ファイル削除（Ajax・ソフトデリート）
     * DELETE /attachments/{attachment}
     */
    public function destroy(Attachment $attachment)
    {
        $user = Auth::user();

        // 部署ベースの認可（IDOR 対策）: 他部署の添付を削除できないようにする
        if (! $this->canAccessDepartmentOf($attachment->attachable_type)) {
            return response()->json(['success' => false, 'message' => 'アクセス権限がありません。'], 403);
        }

        // 権限チェック: 経営層 または アップロード本人
        if (! $user->role->isExecutive() && $attachment->uploaded_by !== $user->id) {
            return response()->json(['success' => false, 'message' => '削除権限がありません。'], 403);
        }

        // deleted_by をセットしてソフトデリート
        $attachment->deleted_by = $user->id;
        $attachment->save();
        $attachment->delete();

        return response()->json([
            'success'  => true,
            'message'  => $attachment->file_name . ' を削除しました。',
            'deleted'  => [
                'id'         => $attachment->id,
                'file_name'  => $attachment->file_name,
                'deleted_by' => $user->name,
                'deleted_at' => $attachment->deleted_at->format('Y/m/d H:i'),
            ],
        ]);
    }

    /**
     * 現在のユーザーが当該 morph クラス（親リソース）の部署にアクセスできるか。
     * executive は全部署許可。マップに無いクラスは安全側に倒して不許可。
     */
    private function canAccessDepartmentOf(string $modelClass): bool
    {
        $user = Auth::user();
        if ($user->isExecutive()) {
            return true;
        }
        $dept = self::DEPARTMENT_MAP[$modelClass] ?? null;

        return $dept !== null && $user->belongsToDepartment($dept);
    }
}
