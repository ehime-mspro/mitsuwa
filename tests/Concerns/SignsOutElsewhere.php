<?php

namespace Tests\Concerns;

use App\Enums\UserStatus;
use Illuminate\Support\Facades\Hash;

/**
 * ログインしたまま、ほかの画面・端末でパスワードが変わった（管理者の再発行も同じ）・無効にされた、を起こす。
 *
 * パスワードが変わると、次の要求で `Illuminate\Session\Middleware\AuthenticateSession` がその場でログインを切り、
 * 認証の確かめが 401 を返す（JSON を求める要求。ふつうのフォームはログイン画面へ転送）。時間がたっただけのときは、
 * 先に鍵の確かめで 419 になるので、401 になるのはこの場面か、ログインが無い（利用者が消された）ときだけ。
 *
 * ⚠ 先にこのログインで 1 回画面を開いておく（セッションのパスワードの控えは、その応答のあとに作られる。控えが無いと
 *   初回に控えを作るだけで切れない）。
 * ⚠ 状態を変えたあとで actingAs を呼び直さない（呼んでも控えは古いままなので結果は同じだが、本物の場面に合わせる）。
 * ⚠ 文はテストの中で定数を引かず、ここに直に書く（アプリの定数を書き換えても緑のまま、を防ぐ）。
 */
trait SignsOutElsewhere
{
    /** 基幹の GET 以外と決裁の URL の JSON の 401（ログインが切れた） */
    protected const SIGNED_OUT_JSON_MESSAGE = 'ログインが切れました。ログインし直してから、もう一度やり直してください。';

    /** 無効にされた人の 401（EnsureUserIsActive） */
    protected const INACTIVE_JSON_MESSAGE = 'このアカウントは無効になっています。管理者にお問い合わせください。';

    /** ほかの画面・端末でパスワードを変えた（次の要求でログインが切れる） */
    protected function changePasswordElsewhere(): void
    {
        auth()->user()->forceFill(['password' => Hash::make('changed-elsewhere-1')])->save();
    }

    /** 管理者がこの人を無効にした（次の要求で EnsureUserIsActive が断る） */
    protected function deactivateSignedInUser(): void
    {
        auth()->user()->forceFill(['status' => UserStatus::Inactive])->save();
    }
}
