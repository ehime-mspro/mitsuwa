<?php

namespace Tests\Feature\Approval\Phase6;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 使い始めたかを読む所を 1 か所に保つ（段階6 設計書 §5.1「判定は 1 か所」・計画 §0.2）。
 *
 * ⚠ コメントや docblock の文字にも当たる。新しいファイルのコメントにも、読み方の名前を書かない。
 */
class LaunchStateScanTest extends TestCase
{
    /** 使い始めたかを読むのは ApprovalSetting と LaunchState だけ（段階6 設計書 §5.1「判定は 1 か所」） */
    public function test_only_the_launch_state_reads_the_launch_flag(): void
    {
        $allowed = ['app/Models/ApprovalSetting.php', 'app/Support/Approval/LaunchState.php'];
        $found   = [];

        foreach (['app', 'resources/views'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                $path = str_replace(base_path() . '/', '', $file->getPathname());
                if (! in_array($path, $allowed, true) && preg_match('/isLaunched\(|launchedForMenu\(|launched_at/', $file->getContents())) {
                    $found[] = $path;
                }
            }
        }

        $this->assertSame([], $found, '使い始めたかは LaunchState から読む');
    }
}
