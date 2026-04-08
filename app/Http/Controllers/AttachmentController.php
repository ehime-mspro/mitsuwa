<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Contract;
use App\Models\Investment;
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
     */
    private const TYPE_MAP = [
        'contracts'    => Contract::class,
        'investments'  => Investment::class,
        'repairs'      => Repair::class,
        'procurements' => ReProcurement::class,
        'projects'     => ReProject::class,
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

        // ファイルの検証
        $request->validate([
            'files'   => 'required|array|min:1',
            'files.*' => 'required|file|max:10240',
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
                'file_path'   => asset('storage/' . $attachment->file_path),
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
     * ファイル削除（Ajax・ソフトデリート）
     * DELETE /attachments/{attachment}
     */
    public function destroy(Attachment $attachment)
    {
        $user = Auth::user();

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
}
