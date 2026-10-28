<?php

use PHPUnit\Framework\TestCase;
use WpSync\Helpers;

/**
 * Runs the import against a fake `ssh` on PATH, which records its arguments and stdin,
 * writes FAKE_SSH_STDERR to stderr and exits with FAKE_SSH_EXIT.
 */
class ImportDumpOverSshTest extends TestCase
{
    private string $bin_dir;
    private string $dump_file;
    private string $original_path;

    private array $config = [
        'host' => 'user@staging.example.com',
        'port' => 2222,
        'path' => '/var/www/my site',
    ];

    protected function setUp(): void
    {
        $this->bin_dir = sys_get_temp_dir() . '/wp-sync-fake-ssh-' . uniqid();
        mkdir($this->bin_dir);

        file_put_contents($this->bin_dir . '/ssh', <<<'SH'
#!/bin/sh
printf '%s\n' "$@" > "$FAKE_SSH_DIR/args"
cat > "$FAKE_SSH_DIR/stdin"
[ -n "$FAKE_SSH_STDERR" ] && echo "$FAKE_SSH_STDERR" >&2
exit "${FAKE_SSH_EXIT:-0}"
SH);
        chmod($this->bin_dir . '/ssh', 0755);

        $this->original_path = getenv('PATH');
        putenv("PATH={$this->bin_dir}:{$this->original_path}");
        putenv("FAKE_SSH_DIR={$this->bin_dir}");
        putenv('FAKE_SSH_EXIT');
        putenv('FAKE_SSH_STDERR');

        $this->dump_file = tempnam(sys_get_temp_dir(), 'wp-sync-dump-');
        file_put_contents($this->dump_file, "CREATE TABLE `wp_options` (`option_id` bigint);\n");
    }

    protected function tearDown(): void
    {
        putenv("PATH={$this->original_path}");
        array_map('unlink', glob($this->bin_dir . '/*'));
        rmdir($this->bin_dir);
        @unlink($this->dump_file);
    }

    private function import(): void
    {
        Helpers::importDumpOverSsh($this->config, $this->dump_file, '--skip-plugins --skip-themes');
    }

    public function testStreamsDumpIntoRemoteImport(): void
    {
        $this->import();

        $this->assertSame(
            "-p\n2222\nuser@staging.example.com\ncd '/var/www/my site' && wp db import - --skip-plugins --skip-themes\n",
            file_get_contents($this->bin_dir . '/args')
        );
        $this->assertSame(
            "CREATE TABLE `wp_options` (`option_id` bigint);\n",
            file_get_contents($this->bin_dir . '/stdin')
        );
    }

    public function testFailedImportStopsWithRemoteOutput(): void
    {
        putenv('FAKE_SSH_EXIT=1');
        putenv('FAKE_SSH_STDERR=ERROR 1064 (42000) at line 3: You have an error in your SQL syntax');

        $this->expectException(WP_CLI_Error::class);
        $this->expectExceptionMessage('ERROR 1064 (42000) at line 3');

        $this->import();
    }
}
