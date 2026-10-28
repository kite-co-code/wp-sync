<?php
// Runs Helpers::normalizeDumpCollations() on a dump file, faking the destination's
// SHOW COLLATION output with the file named in $SUPPORTED. Remaps go to stderr.
// Usage: SUPPORTED=collations.txt php normalize.php dump.sql

class WP_CLI
{
    public static function runcommand($command, $options = [])
    {
        return file_get_contents(getenv('SUPPORTED'));
    }

    public static function log($message)
    {
        fwrite(STDERR, preg_replace('/%[a-zA-Z]/', '', $message) . "\n");
    }

    public static function warning($message)
    {
        fwrite(STDERR, "Warning: $message\n");
    }

    public static function colorize($message)
    {
        return $message;
    }
}

require dirname(__DIR__, 2) . '/src/Helpers.php';
\WpSync\Helpers::normalizeDumpCollations($argv[1]);
