<?php

use PHPUnit\Framework\TestCase;
use WpSync\Helpers;

class BuildConfigTest extends TestCase
{
    private function build(array $assoc_args, array $pull_config = []): array
    {
        $yaml_config = [
            'pull' => $pull_config,
            'environments' => [
                'staging' => ['host' => 'user@staging.example.com', 'path' => '/var/www/staging'],
            ],
        ];

        return Helpers::buildConfig('pull', 'staging', $assoc_args, $yaml_config);
    }

    /**
     * WP-CLI passes `--db=false` as the string "false", which PHP treats as true.
     */
    public function testFalseFlagOverridesConfigFileTrue(): void
    {
        $config = $this->build(['db' => 'false'], ['db' => true]);

        $this->assertFalse($config['db']);
    }

    /**
     * Pull and push check `search_replace_urls === true`, so "true" must become a real boolean.
     */
    public function testTrueFlagBecomesBoolean(): void
    {
        $config = $this->build(['search_replace_urls' => 'true'], ['search_replace_urls' => false]);

        $this->assertTrue($config['search_replace_urls']);
    }

    public function testVerboseFlag(): void
    {
        // WP-CLI passes a bare `--verbose` as boolean true.
        $this->assertTrue($this->build(['verbose' => true])['verbose']);
        $this->assertFalse($this->build(['verbose' => 'false'])['verbose']);
    }

    public function testUnreadableBooleanStopsWithError(): void
    {
        $this->expectException(WP_CLI_Error::class);
        $this->expectExceptionMessage("'db'");

        $this->build(['db' => 'maybe']);
    }
}
