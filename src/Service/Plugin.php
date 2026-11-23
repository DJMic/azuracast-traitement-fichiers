<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Service;

final class Plugin
{
    public const string NAME = 'azuracast-traitement-fichiers';

    /** Prefix of the plugin's own HTTP routes. */
    public const string ROUTE_PREFIX = '/traitement-fichiers';

    public static function dir(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function version(): string
    {
        static $version = null;
        if ($version === null) {
            $raw = @file_get_contents(self::dir() . '/VERSION');
            $version = is_string($raw) && trim($raw) !== '' ? trim($raw) : 'dev';
        }

        return $version;
    }
}
