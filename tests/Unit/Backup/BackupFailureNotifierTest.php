<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\BackupFailureNotifier;
use PHPUnit\Framework\TestCase;

class BackupFailureNotifierTest extends TestCase
{
    public function test_splits_on_commas_semicolons_and_japanese_separators(): void
    {
        [$valid, $invalid] = BackupFailureNotifier::recipients(
            'a@example.com,b@example.com;c@example.com、d@example.com，e@example.com；f@example.com'
        );

        $this->assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com', 'e@example.com', 'f@example.com'],
            $valid,
        );
        $this->assertSame([], $invalid);
    }

    public function test_splits_on_whitespace_including_fullwidth_space_and_ignores_empty_tokens(): void
    {
        [$valid, $invalid] = BackupFailureNotifier::recipients(" a@example.com \t b@example.com\u{3000}c@example.com ,, ");

        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $valid);
        $this->assertSame([], $invalid);
    }

    public function test_duplicate_valid_addresses_are_removed_keeping_first_occurrence_order(): void
    {
        [$valid, $invalid] = BackupFailureNotifier::recipients('b@example.com,a@example.com,b@example.com');

        $this->assertSame(['b@example.com', 'a@example.com'], $valid);
        $this->assertSame([], $invalid);
    }

    public function test_malformed_addresses_are_collected_as_invalid(): void
    {
        [$valid, $invalid] = BackupFailureNotifier::recipients('a@example.com, it@example,com');

        $this->assertSame(['a@example.com'], $valid);
        $this->assertSame(['it@example', 'com'], $invalid);
    }

    public function test_null_configured_value_yields_no_recipients(): void
    {
        $this->assertSame([[], []], BackupFailureNotifier::recipients(null));
    }

    public function test_empty_string_yields_no_recipients(): void
    {
        $this->assertSame([[], []], BackupFailureNotifier::recipients(''));
    }

    public function test_problems_reports_invalid_addresses_joined_by_japanese_comma(): void
    {
        $notifier = new BackupFailureNotifier('a@example.com, it@example,com');

        $this->assertSame(
            ['BACKUP_NOTIFY_TO に形式の誤ったアドレスがあります: it@example、com'],
            $notifier->problems(),
        );
    }

    public function test_problems_reports_when_there_are_no_valid_recipients(): void
    {
        $notifier = new BackupFailureNotifier('');

        $this->assertSame(
            ['BACKUP_NOTIFY_TO に有効な宛先がありません。失敗しても誰にも知らせが届きません。'],
            $notifier->problems(),
        );
    }

    public function test_problems_reports_both_messages_in_order_when_every_token_is_invalid(): void
    {
        $notifier = new BackupFailureNotifier('not-an-email');

        $this->assertSame(
            [
                'BACKUP_NOTIFY_TO に形式の誤ったアドレスがあります: not-an-email',
                'BACKUP_NOTIFY_TO に有効な宛先がありません。失敗しても誰にも知らせが届きません。',
            ],
            $notifier->problems(),
        );
    }

    public function test_problems_is_empty_when_every_address_is_valid(): void
    {
        $notifier = new BackupFailureNotifier('a@example.com, b@example.com');

        $this->assertSame([], $notifier->problems());
    }

    public function test_invalid_utf8_bytes_are_scrubbed_instead_of_raising_an_error(): void
    {
        // \xFF は不正な UTF-8 バイト列。mb_scrub で置換文字（既定は '?'）に置き換えてから分割するため、
        // 何も対策しなければ preg_split(/u) が false を返して例外になっていたのを防げる。
        // なお '?' は RFC 5322 の atext として許可される文字のため、'?@example.com' は
        // filter_var(FILTER_VALIDATE_EMAIL) では有効と判定される（実機で確認済み）。
        // ここで確かめたいのは「不正な UTF-8 を渡しても例外にならず処理が終わる」こと。
        [$valid, $invalid] = BackupFailureNotifier::recipients("a@example.com,\xff@example.com");

        $this->assertSame(['a@example.com', '?@example.com'], $valid);
        $this->assertSame([], $invalid);
    }
}
