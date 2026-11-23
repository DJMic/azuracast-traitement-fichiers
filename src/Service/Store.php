<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Service;

use JsonException;
use RuntimeException;

/**
 * Files of the plugin, kept in each station's configuration folder
 * (/var/azuracast/stations/<station>/config/), which survives AzuraCast and plugin updates:
 *  - traitement-fichiers.json        settings of the tab;
 *  - traitement-fichiers-etat.json   working state: files that could not be analysed
 *                                     (retried later) and the "existing files" pass.
 */
final class Store
{
    public const string SETTINGS_FILE = 'traitement-fichiers.json';
    public const string STATE_FILE = 'traitement-fichiers-etat.json';
    private const string LOCK_FILE = 'traitement-fichiers.lock';

    public function readSettings(string $configDir): array
    {
        return Settings::withDefaults(self::load($configDir . '/' . self::SETTINGS_FILE));
    }

    public function writeSettings(string $configDir, array $settings): void
    {
        $this->locked($configDir, function () use ($configDir, $settings): void {
            self::save($configDir . '/' . self::SETTINGS_FILE, Settings::withDefaults($settings));
        });
    }

    public function readState(string $configDir): array
    {
        return self::stateWithDefaults(self::load($configDir . '/' . self::STATE_FILE));
    }

    /**
     * Read-modify-write of the state under a lock (the background task and the tab may
     * change it at the same time).
     *
     * @param callable(array): array $change
     */
    public function updateState(string $configDir, callable $change): array
    {
        return $this->locked($configDir, function () use ($configDir, $change): array {
            $file = $configDir . '/' . self::STATE_FILE;
            $state = self::stateWithDefaults($change(self::stateWithDefaults(self::load($file))));
            self::save($file, $state);

            return $state;
        });
    }

    public static function stateWithDefaults(array $state): array
    {
        $failures = [];
        foreach ((array)($state['failures'] ?? []) as $id => $failure) {
            if (!is_array($failure) || !ctype_digit((string)$id)) {
                continue;
            }
            $failures[(string)$id] = [
                'mtime' => (int)($failure['mtime'] ?? 0),
                'count' => max(1, (int)($failure['count'] ?? 1)),
                'next_at' => (int)($failure['next_at'] ?? 0),
                'error' => mb_substr((string)($failure['error'] ?? ''), 0, 500),
                'path' => mb_substr((string)($failure['path'] ?? ''), 0, 500),
            ];
        }

        return [
            'backfill' => (bool)($state['backfill'] ?? false),
            'failures' => $failures,
        ];
    }

    /**
     * @template T
     * @param callable(): T $action
     * @return T
     */
    private function locked(string $configDir, callable $action): mixed
    {
        if (!is_dir($configDir) && !@mkdir($configDir, 0775, true) && !is_dir($configDir)) {
            throw new RuntimeException('Dossier de configuration de la station introuvable : ' . $configDir);
        }
        $lock = @fopen($configDir . '/' . self::LOCK_FILE, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Impossible de verrouiller les fichiers du plugin dans ' . $configDir);
        }
        try {
            return $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function load(string $file): array
    {
        clearstatcache(true, $file);
        if (!is_file($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Lecture impossible : ' . $file);
        }
        if (trim($raw) === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Fichier illisible (' . $e->getMessage() . ') : ' . $file);
        }

        return is_array($data) ? $data : [];
    }

    /** Atomic write (temporary file + rename). */
    public static function save(string $file, array $data): void
    {
        $dir = dirname($file);
        $tmp = @tempnam($dir, '.tf-');
        if ($tmp === false) {
            throw new RuntimeException('Écriture impossible dans ' . $dir);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json . "\n") === false || !chmod($tmp, 0644) || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Écriture impossible : ' . $file);
        }
    }
}
