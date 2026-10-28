<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpSync\Helpers;

class NormalizeDumpCollationsTest extends TestCase
{
    private string $dump_file;

    protected function setUp(): void
    {
        $this->dump_file = tempnam(sys_get_temp_dir(), 'wp-sync-dump-');
        WP_CLI::$logs = [];

        // Destination is MySQL-like: no MariaDB UCA-1400 collations.
        WP_CLI::$runcommand_output = implode("\n", [
            "utf8mb4_general_ci\tutf8mb4\t45\tYes\tYes\t1\tPAD SPACE",
            "utf8mb4_unicode_ci\tutf8mb4\t224\t\tYes\t8\tPAD SPACE",
            "utf8mb4_unicode_520_ci\tutf8mb4\t246\t\tYes\t8\tPAD SPACE",
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dump_file);
    }

    private function normalize(string $sql): string
    {
        file_put_contents($this->dump_file, $sql);
        Helpers::normalizeDumpCollations($this->dump_file);
        return file_get_contents($this->dump_file);
    }

    /**
     * Wrap rows in an INSERT statement the way each dump tool writes it.
     */
    private static function insert(string $format, string $table, array $rows): string
    {
        if ($format === 'mysqldump') {
            // One line per statement: INSERT INTO `t` VALUES (...),(...);
            return "INSERT INTO `$table` VALUES " . implode(',', $rows) . ";\n";
        }

        // mariadb-dump 11+: statement header on its own line, then one row per line.
        return "INSERT INTO `$table` VALUES\n" . implode(",\n", $rows) . ";\n";
    }

    public static function dumpFormats(): array
    {
        return [
            'mysqldump' => ['mysqldump'],
            'mariadb-dump' => ['mariadb-dump'],
        ];
    }

    public function testRemapsUnsupportedCollationInSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `wp_options` (
  `option_name` varchar(191) COLLATE utf8mb4_uca1400_ai_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!50003 SET collation_connection  = utf8mb4_uca1400_ai_ci */ ;

SQL;

        $expected = <<<'SQL'
CREATE TABLE `wp_options` (
  `option_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;

SQL;

        $this->assertSame($expected, $this->normalize($sql));
    }

    public function testLeavesDumpUntouchedWhenAllCollationsSupported(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `wp_posts` (
  `post_title` text COLLATE utf8mb4_unicode_520_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

SQL;

        $this->assertSame($sql, $this->normalize($sql));
        $this->assertSame([], WP_CLI::$logs);
    }

    /**
     * Regression test for https://github.com/kite-co-code/wp-sync/issues/6:
     * prose like "collate the" in row data was read as a collation named "the",
     * and every "the" in the dump was rewritten, corrupting serialized data.
     */
    #[DataProvider('dumpFormats')]
    public function testIgnoresCollateWordsInRowData(string $format): void
    {
        $sql = <<<'SQL'
CREATE TABLE `wp_posts` (
  `post_content` longtext COLLATE utf8mb4_unicode_520_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

SQL;
        $sql .= self::insert($format, 'wp_options', [
            "('wp_user_roles','a:1:{s:13:\\\"administrator\\\";a:1:{s:4:\\\"name\\\";s:13:\\\"Administrator\\\";}}')",
            "('blurb','We collate the results for the theme.')",
        ]);

        $this->assertSame($sql, $this->normalize($sql));
        $this->assertSame([], WP_CLI::$logs);
    }

    /**
     * Row data must not be scanned for collations at all: "collate post_title" in
     * content looks like a collation name, and remapping it would rename the
     * `post_title` column in the schema.
     */
    #[DataProvider('dumpFormats')]
    public function testDoesNotDetectCollationsFromRowData(string $format): void
    {
        $sql = <<<'SQL'
CREATE TABLE `wp_posts` (
  `post_title` text COLLATE utf8mb4_unicode_520_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

SQL;
        $sql .= self::insert($format, 'wp_posts', [
            "('Remember to collate post_title values first.')",
            "('Another row')",
        ]);

        $this->assertSame($sql, $this->normalize($sql));
        $this->assertSame([], WP_CLI::$logs);
    }

    /**
     * Column comments live on schema lines, so skipping row data doesn't cover them.
     * Only underscore-containing names count as collations, so "collate the" here is ignored.
     */
    public function testIgnoresCollateWordsInColumnComments(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `wp_posts` (
  `post_content` longtext COLLATE utf8mb4_unicode_520_ci NOT NULL COMMENT 'We collate the posts here'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

SQL;

        $this->assertSame($sql, $this->normalize($sql));
        $this->assertSame([], WP_CLI::$logs);
    }

    #[DataProvider('dumpFormats')]
    public function testDoesNotRewriteCollationNamesInRowData(string $format): void
    {
        $schema = <<<'SQL'
CREATE TABLE `wp_posts` (
  `post_content` longtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=%s;

SQL;
        $rows = self::insert($format, 'wp_posts', [
            "('Set COLLATE utf8mb4_uca1400_ai_ci on the table.')",
            "('utf8mb4_uca1400_ai_ci')",
        ]);

        $sql = sprintf($schema, 'utf8mb4_uca1400_ai_ci') . $rows;
        $expected = sprintf($schema, 'utf8mb4_unicode_ci') . $rows;

        $this->assertSame($expected, $this->normalize($sql));
    }

    public function testOnlyReplacesWholeCollationNames(): void
    {
        // utf8mb4_uca1400_ai_ci is unsupported, but must not be replaced inside a longer name.
        $sql = <<<'SQL'
CREATE TABLE `t` (
  `a` text COLLATE utf8mb4_uca1400_ai_ci,
  `utf8mb4_uca1400_ai_ci_backup` text COLLATE utf8mb4_unicode_ci
);

SQL;

        $expected = <<<'SQL'
CREATE TABLE `t` (
  `a` text COLLATE utf8mb4_unicode_ci,
  `utf8mb4_uca1400_ai_ci_backup` text COLLATE utf8mb4_unicode_ci
);

SQL;

        $this->assertSame($expected, $this->normalize($sql));
    }
}
