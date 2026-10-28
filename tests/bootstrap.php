<?php

// Load the autoloader before defining the WP_CLI stub so command.php bails out
// instead of trying to register commands.
require dirname(__DIR__) . '/vendor/autoload.php';

class WP_CLI_Error extends RuntimeException
{
}

/**
 * Minimal WP_CLI stand-in. Tests set $runcommand_output to fake what the
 * destination server returns; log lines are collected in $logs.
 */
class WP_CLI
{
    public static string $runcommand_output = '';
    public static array $logs = [];

    public static function runcommand($command, $options = [])
    {
        return self::$runcommand_output;
    }

    public static function log($message)
    {
        self::$logs[] = $message;
    }

    public static function warning($message)
    {
        self::$logs[] = "Warning: $message";
    }

    /**
     * The real WP_CLI::error() halts the script; throwing lets tests observe that.
     */
    public static function error($message)
    {
        throw new WP_CLI_Error($message);
    }

    public static function colorize($message)
    {
        return preg_replace('/%[a-zA-Z]/', '', $message);
    }
}
