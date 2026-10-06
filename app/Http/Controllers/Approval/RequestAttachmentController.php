<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalRequest;
use App\Support\Approval\DownloadLogger;
use App\Support\Approval\RequestContent;
use App\Support\Approval\RequestPermissions;
use App\Support\Approval\RequestVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 決裁の添付（要件 5.3・14.3・設計書 §5.7・計画 §0.5）。
 *
 * ⚠ ファイルは local ディスク（storage/app/private）に置く。public ディスクに置かない（14.3 の「非公開」）。
 * ⚠ 開くたびに RequestVisibility で確かめる（URL を知っていても、見られない人は 404）。
 *   申請者以外には、いずれかの回で提出した添付だけを開かせる（差戻し中に足した添付は、出し直すまで申請者だけ。
 *   RequestContent::mayOpen()。利用者の決定 2026-09-27）。
 * ⚠ 足す・外すは、申請の行をロックしてから状態を確かめて書く。提出（Workflow::submit）も申請の行を先にロックするので、
 *   提出と重なっても、控えに無い添付が回覧中の申請に付かない（Task 7 の点検の申し送り）。
 * ⚠ 上書きしない（新しい内容は新しい名前。夜間バックアップは同じパス・同じ大きさを送り直さない）。
 * ⚠ 一度も提出していない下書き（round = 0）の添付は、外すと行もファイルも消え、開いても記録しない。
 *   一度でも提出した申請の添付は、外しても行とファイルを残し、開くたびに記録する（計画 §0.5）。
 */
class RequestAttachmentController extends Controller
{
    /** 追加（1 ファイル・Ajax・JSON。画面の JS が 1 つずつ順に送る。D14） */
    public function store(Request $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        $user = $request->user();
        abort_unless(RequestVisibility::canView($user, $approvalRequest), 404);

        $request->validate([
            'file' => ['required', 'file', 'max:' . ApprovalAttachment::MAX_KB, 'mimes:' . implode(',', array_keys(ApprovalAttachment::TYPES))],
        ], [
            'file.max'   => '1 ファイル 10MB までです。',
            'file.mimes' => '添付できるのは、画像（jpg・png・gif・webp・heic）・PDF・Word・Excel・CSV・テキストです。',
        ], [
            'file' => '添付ファイル',
        ]);

        $file = $request->file('file');
        // ⚠ 名前に / と \ があると、開くときの Content-Disposition が作れない
        $originalName = str_replace(['/', '\\'], '_', $file->getClientOriginalName());

        if (mb_strlen($originalName) > 255) {
            return response()->json(['message' => 'ファイル名が長すぎます（255 文字まで）。名前を短くしてから選んでください。'], 422);
        }

        // 拡張子は中身から決める（mimes の検査と同じもの。送られてきた名前や MIME は信じない）
        $extension = $file->guessExtension();

        return DB::transaction(function () use ($approvalRequest, $user, $file, $originalName, $extension): JsonResponse {
            // ⚠ 申請の行をロックしてから確かめる（別のタブで提出した直後・同時の送信で 20 を超えない）。提出も申請の行を
            //   先にロックするので、ここを待たせた提出の控えにはこの添付が入り、提出のあとに来た添付は下で断る
            $locked = ApprovalRequest::whereKey($approvalRequest->id)->lockForUpdate()->firstOrFail();

            if (! RequestPermissions::for($user, $locked)->canEdit()) {
                return response()->json(['message' => '添付を足せるのは、下書きと差戻し中の申請者だけです。'], 403);
            }

            if ($locked->attachments()->count() >= ApprovalAttachment::MAX_COUNT) {
                return response()->json(['message' => '添付は 1 件の申請に ' . ApprovalAttachment::MAX_COUNT . ' ファイルまでです。'], 422);
            }

            $path = $file->storeAs("approvals/{$locked->id}", Str::random(40) . '.' . $extension, 'local');

            $attachment = ApprovalAttachment::create([
                'request_id'    => $locked->id,
                'original_name' => $originalName,
                'stored_path'   => $path,
                'mime'          => ApprovalAttachment::TYPES[$extension],
                'size'          => $file->getSize(),
                'uploaded_by'   => $user->id,
                // この添付が初めて入る提出の回（差戻し中なら次の出し直し）
                'added_round'   => $locked->round + 1,
            ]);

            return response()->json([
                'success'    => true,
                'message'    => "{$originalName} を添付しました。",
                'attachment' => $attachment->listItem(),
            ]);
        });
    }

    /** 開く・ダウンロード（毎回、見られる範囲を確かめる。§5.7） */
    public function show(Request $request, ApprovalAttachment $approvalAttachment): StreamedResponse
    {
        $user            = $request->user();
        $approvalRequest = $approvalAttachment->request;
        abort_unless(RequestVisibility::canView($user, $approvalRequest), 404);
        // 申請者以外に開かせるのは、いずれかの回で提出した添付だけ（直しかけの回に足した添付は、出し直すまで申請者だけ。
        // 在ることも漏らさないので 404）
        abort_unless(RequestContent::mayOpen($user, $approvalAttachment), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($approvalAttachment->stored_path), 404);

        // 一度でも提出した申請の添付は、開くたびに記録する（ブラウザで開いた場合も。14.2・計画 §0.5）
        if ($approvalRequest->round >= 1) {
            DownloadLogger::attachment($request, $approvalAttachment);
        }

        // 日本語の名前は filename*（UTF-8）で渡し、古いブラウザ用に ASCII の代わりの名前も付ける。
        // ⚠ 代わりの名前を Laravel に任せない。Str::ascii() は仮名・漢字を消すので、「見積書」のように日本語だけで拡張子の無い
        //   名前は代わりの名前が空になり、Symfony が例外を投げて 500 になる（提出したあとは誰も開けず、直すこともできない）。
        //   ASCII で残らなければ「attachment.拡張子」にする
        $name     = $approvalAttachment->original_name;
        $fallback = trim(str_replace(['%', '/', '\\'], '', Str::ascii($name)));
        if ($fallback === '' || ! preg_match('/^[\x20-\x7e]+$/', $fallback)) {
            $fallback = 'attachment.' . $approvalAttachment->extension();
        }

        return $disk->response(
            $approvalAttachment->stored_path,
            $name,
            [
                'Content-Type'           => ApprovalAttachment::TYPES[$approvalAttachment->extension()],
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition'    => HeaderUtils::makeDisposition($approvalAttachment->opensInline() ? 'inline' : 'attachment', $name, $fallback),
            ],
        );
    }

    /** 外す（Ajax・JSON。計画 §0.5 の表のとおり、下書きなら消し、提出したことがあれば外すだけ） */
    public function destroy(Request $request, ApprovalAttachment $approvalAttachment): JsonResponse
    {
        $user = $request->user();
        abort_unless(RequestVisibility::canView($user, $approvalAttachment->request), 404);
        // 開けない添付（差戻し中に足して、まだ出し直していないもの）は、外す要求でも 404（show() と同じ。403 にすると、
        // ID を知っている人に在ることが分かる。利用者の決定 2026-09-27）
        abort_unless(RequestContent::mayOpen($user, $approvalAttachment), 404);

        $fileToDelete = null;

        $response = DB::transaction(function () use ($approvalAttachment, $user, &$fileToDelete): JsonResponse {
            // ⚠ 足すときと同じく、申請の行をロックしてから確かめる（提出と重なっても、控えと食い違わない）
            $locked = ApprovalRequest::whereKey($approvalAttachment->request_id)->lockForUpdate()->firstOrFail();

            if (! RequestPermissions::for($user, $locked)->canEdit()) {
                return response()->json(['message' => '添付を外せるのは、下書きと差戻し中の申請者だけです。'], 403);
            }

            if ($approvalAttachment->removed_at !== null) {
                return response()->json(['message' => 'この添付はもう外してあります。画面を開き直してください。'], 422);
            }

            if ($locked->round === 0) {
                // 一度も提出していない: 記録（控え・ダウンロード）が無いので行ごと消す
                $approvalAttachment->delete();
                $fileToDelete = $approvalAttachment->stored_path;
            } else {
                // 一度でも提出した: 控えと記録から開けるように残し、一覧から外すだけ
                $approvalAttachment->update(['removed_round' => $locked->round + 1, 'removed_at' => now()]);
            }

            return response()->json(['success' => true, 'message' => "{$approvalAttachment->original_name} を外しました。"]);
        });

        // ファイルは行の削除が確定してから消す
        if ($fileToDelete !== null) {
            Storage::disk('local')->delete($fileToDelete);
        }

        return $response;
    }
}
