<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * URLs of the back office assets. Both files live in public/ and are served
 * directly by the web server; the file mtime is appended so browsers pick up
 * edits without a manual cache-bust.
 */
final class AdminTheme
{
    public static function cssFile(): string
    {
        return dirname(__DIR__, 2).'/public/admin.css';
    }

    public static function cssUrl(): string
    {
        $path = self::cssFile();

        return '/admin.css?v='.(is_file($path) ? filemtime($path) : '0');
    }

    public static function jsFile(): string
    {
        return dirname(__DIR__, 2).'/public/admin.js';
    }

    public static function jsUrl(): string
    {
        $path = self::jsFile();

        return '/admin.js?v='.(is_file($path) ? filemtime($path) : '0');
    }
}
