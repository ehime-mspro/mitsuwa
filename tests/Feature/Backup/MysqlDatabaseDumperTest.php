<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\MysqlDatabaseDumper;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class MysqlDatabaseDumperTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().'/dumper-'.bin2hex(random_bytes(4)).'/backup-work';
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg(dirname($this->workDir)));
        parent::tearDown();
    }

    public function test_password_goes_to_the_options_file_not_the_command_line(): void
    {
        $command = $this->dumper()->command('/tmp/opts.cnf', '/tmp/out.sql');

        $this->assertSame('/usr/local/bin/mysqldump', $command[0]);
        $this->assertSame('--defaults-extra-file=/tmp/opts.cnf', $command[1]);
        $this->assertContains('--single-transaction', $command);
        $this->assertContains('--set-gtid-purged=OFF', $command);
        $this->assertSame(['--result-file=/tmp/out.sql', 'manage_db'], array_slice($command, -2));
        foreach ($command as $part) {
            $this->assertStringNotContainsString('p@ss', $part);
        }
    }

    public function test_options_file_holds_the_credentials(): void
    {
        $this->assertSame(
            "[client]\nuser=\"manage_user\"\npassword=\"p@ss w0rd\\\\x\"\nhost=\"mysql.example.jp\"\nport=3306\n",
            $this->dumper()->optionsFileContents(),
        );
    }

    public function test_extra_options_come_just_before_the_result_file(): void
    {
        $dumper = new MysqlDatabaseDumper(['database' => 'manage_db', 'username' => 'u'], 'mysqldump', ' --column-statistics=0  --hex-blob ', 60, $this->workDir);

        $this->assertSame(
            ['--column-statistics=0', '--hex-blob', '--result-file=/tmp/out.sql', 'manage_db'],
            array_slice($dumper->command('/tmp/o.cnf', '/tmp/out.sql'), -4),
        );
    }

    public function test_unix_socket_replaces_host_and_port(): void
    {
        $contents = $this->dumper(['unix_socket' => '/tmp/mysql.sock'])->optionsFileContents();

        $this->assertStringContainsString('socket="/tmp/mysql.sock"', $contents);
        $this->assertStringNotContainsString('host=', $contents);
    }

    public function test_successful_dump_removes_the_options_file(): void
    {
        Process::fake(function (PendingProcess $process) {
            foreach ((array) $process->command as $part) {
                if (str_starts_with($part, '--result-file=')) {
                    file_put_contents(substr($part, strlen('--result-file=')), "-- dump\n");
                }
            }

            return Process::result();
        });

        $this->dumper()->dumpTo($this->workDir.'/dump.sql');

        $this->assertStringEqualsFile($this->workDir.'/dump.sql', "-- dump\n");
        $this->assertSame([$this->workDir.'/dump.sql'], glob($this->workDir.'/*'));
        Process::assertRan(fn (PendingProcess $process) => $process->timeout === 1800);
    }

    public function test_failed_dump_reports_the_error_and_removes_the_options_file(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'Access denied for user', exitCode: 2));

        $caught = null;
        try {
            $this->dumper()->dumpTo($this->workDir.'/dump.sql');
        } catch (RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('Access denied', $caught->getMessage());
        $this->assertSame([], glob($this->workDir.'/*'));
    }

    public function test_missing_dump_file_is_an_error(): void
    {
        Process::fake();

        $this->expectExceptionMessage('ダンプファイルが作られませんでした');
        $this->dumper()->dumpTo($this->workDir.'/dump.sql');
    }

    public function test_quote_in_credentials_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->dumper(['password' => 'a"b'])->optionsFileContents();
    }

    private function dumper(array $overrides = []): MysqlDatabaseDumper
    {
        return new MysqlDatabaseDumper(array_merge([
            'host' => 'mysql.example.jp',
            'port' => '3306',
            'database' => 'manage_db',
            'username' => 'manage_user',
            'password' => 'p@ss w0rd\\x',
        ], $overrides), '/usr/local/bin/mysqldump', '', 1800, $this->workDir);
    }
}
