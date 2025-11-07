<?php
declare(strict_types=1);

namespace App\Lib;

final class Logger
{
    private static string $file = __DIR__ . '/../../logs/latest.log';

    public static function setFile(string $path): void
    {
        self::$file = $path;
    }

    public static function info(string $msg, array $ctx = []): void
    {
        self::write('INFO', $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write('ERROR', $msg, $ctx);
    }

    private static function write(string $level, string $msg, array $ctx = []): void
    {
        $line = json_encode([
            'ts'   => date('c'),
            'lvl'  => $level,
            'msg'  => $msg,
            'ctx'  => $ctx,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;

        @file_put_contents(self::$file, $line, FILE_APPEND);
        @error_log("[ColorFix][$level] $msg " . (empty($ctx) ? '' : json_encode($ctx)));
    }
}
