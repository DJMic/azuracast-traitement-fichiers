<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Service;

/**
 * Finds the files to analyse in AzuraCast's database and writes the results.
 *
 * A file is analysed only if ALL its custom cue values are empty (cue_in, cue_out, fade_in,
 * fade_out, cross_start_next, amplify). A file that already has one of them, from its tags or
 * typed in AzuraCast, is never touched. A file whose audio was replaced is not re-analysed
 * either: its values are still there.
 */
final class MediaFinder
{
    public const array FIELDS = ['cue_in', 'cue_out', 'fade_in', 'fade_out', 'cross_start_next', 'amplify'];

    /** Seconds to wait after AzuraCast has processed a file (its "mtime") before analysing it. */
    public const int SETTLE_DELAY = 60;

    public function __construct(
        private readonly Sql $sql
    ) {
    }

    /**
     * Files to analyse, oldest first.
     *
     * @param bool $existingToo Also files added before the plugin was activated (the "existing files" pass).
     * @param int[] $skipIds Files to leave aside (failed recently).
     *
     * @return list<array{id: int, path: string, length: float, size: int, mtime: int, uploaded_at: int}>
     */
    public function candidates(
        int $storageLocationId,
        array $settings,
        bool $existingToo,
        int $now,
        array $skipIds = [],
        int $limit = 25
    ): array {
        [$where, $params] = $this->conditions($storageLocationId, $settings, $existingToo, $skipIds);
        $where[] = 'sm.mtime <= ?';
        $params[] = $now - self::SETTLE_DELAY;

        $rows = $this->sql->fetchAll(
            'SELECT sm.id, sm.path, sm.length, sm.size, sm.mtime, sm.uploaded_at, sm.extra_metadata'
            . ' FROM station_media sm WHERE ' . implode(' AND ', $where)
            . ' ORDER BY sm.uploaded_at ASC, sm.id ASC LIMIT ' . max(1, $limit),
            $params
        );

        $result = [];
        foreach ($rows as $row) {
            // Second check in PHP, independent of the database's JSON functions.
            if (!self::isEmpty($row['extra_metadata'] ?? null)) {
                continue;
            }
            $result[] = [
                'id' => (int)$row['id'],
                'path' => (string)$row['path'],
                'length' => (float)$row['length'],
                'size' => (int)$row['size'],
                'mtime' => (int)$row['mtime'],
                'uploaded_at' => (int)$row['uploaded_at'],
            ];
        }

        return $result;
    }

    /** Number of files that still need an analysis (including those AzuraCast has just processed). */
    public function count(int $storageLocationId, array $settings, bool $existingToo, array $skipIds = []): int
    {
        [$where, $params] = $this->conditions($storageLocationId, $settings, $existingToo, $skipIds);
        $rows = $this->sql->fetchAll(
            'SELECT COUNT(*) AS n FROM station_media sm WHERE ' . implode(' AND ', $where),
            $params
        );

        return (int)($rows[0]['n'] ?? 0);
    }

    /**
     * Writes the values, only if the file was not processed again by AzuraCast in the meantime
     * (same mtime) and if its values are still all empty (nobody typed one meanwhile).
     * The audio file itself is not modified, and neither is its mtime.
     *
     * @param array<string, float> $values
     */
    public function write(int $mediaId, int $mtime, array $values): bool
    {
        $set = [];
        foreach (self::FIELDS as $field) {
            if (!isset($values[$field]) || !is_finite((float)$values[$field])) {
                throw new \InvalidArgumentException('Valeur manquante : ' . $field);
            }
            // Numeric literal (not a bound string) so that MariaDB stores a JSON number.
            $set[] = sprintf("'$.%s', %s", $field, self::number((float)$values[$field]));
        }

        $this->sql->ensureConnected();

        return $this->sql->execute(
            'UPDATE station_media sm SET sm.extra_metadata = JSON_SET('
            . "CASE WHEN JSON_TYPE(sm.extra_metadata) = 'OBJECT' THEN sm.extra_metadata ELSE '{}' END, "
            . implode(', ', $set) . ')'
            . ' WHERE sm.id = ? AND sm.mtime = ? AND ' . self::emptyCondition(),
            [$mediaId, $mtime]
        ) === 1;
    }

    /**
     * Folders of a media storage location, from the paths of its files (the storage itself is
     * never listed: with S3 that would mean listing the whole bucket). Each folder comes with the
     * number of files it holds, subfolders included.
     *
     * @return list<array{path: string, files: int}> Sorted like a tree ("A", "A/B", "B"…).
     */
    public function folders(int $storageLocationId): array
    {
        $rows = $this->sql->fetchAll(
            'SELECT path FROM station_media WHERE storage_location_id = ?',
            [$storageLocationId]
        );
        $counts = [];
        foreach ($rows as $row) {
            $parts = explode('/', (string)$row['path']);
            array_pop($parts); // file name
            $folder = '';
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                $folder = $folder === '' ? $part : $folder . '/' . $part;
                $counts[$folder] = ($counts[$folder] ?? 0) + 1;
            }
        }
        // Tree order: "/" sorts before any other character, so that "A/B" follows "A" directly.
        uksort($counts, static fn(string $a, string $b): int => strcmp(
            mb_strtolower(str_replace('/', "\x01", $a)),
            mb_strtolower(str_replace('/', "\x01", $b))
        ));
        $folders = [];
        foreach ($counts as $path => $files) {
            $folders[] = ['path' => (string)$path, 'files' => $files];
        }

        return $folders;
    }

    /** True if none of the six custom cue values is set (raw JSON of station_media.extra_metadata). */
    public static function isEmpty(mixed $rawJson): bool
    {
        if ($rawJson === null || $rawJson === '') {
            return true;
        }
        $data = is_array($rawJson) ? $rawJson : json_decode((string)$rawJson, true);
        if (!is_array($data)) {
            return true;
        }
        foreach (self::FIELDS as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: string[], 1: array} */
    private function conditions(int $storageLocationId, array $settings, bool $existingToo, array $skipIds): array
    {
        $where = [
            'sm.storage_location_id = ?',
            'sm.length > 0',
            'sm.length <= ?',
            self::emptyCondition(),
        ];
        $params = [$storageLocationId, Settings::MAX_DURATION];

        if (!$existingToo) {
            $where[] = 'sm.uploaded_at >= ?';
            $params[] = max(1, (int)$settings['activated_at']);
        }

        $skip = self::intList($skipIds);
        if ($skip !== '') {
            $where[] = 'sm.id NOT IN (' . $skip . ')';
        }

        // Excluded folders, subfolders included. Compared like AzuraCast's database compares paths
        // (case-insensitive with its default collation).
        foreach (Settings::folders($settings['excluded_folders'] ?? []) as $folder) {
            $where[] = "sm.path NOT LIKE ? ESCAPE '!'";
            $params[] = strtr($folder, ['!' => '!!', '%' => '!%', '_' => '!_']) . '/%';
        }

        return [$where, $params];
    }

    private static function emptyCondition(): string
    {
        $checks = array_map(
            static fn(string $field): string => sprintf(
                "COALESCE(JSON_TYPE(JSON_EXTRACT(sm.extra_metadata, '$.%s')), 'NULL') = 'NULL'",
                $field
            ),
            self::FIELDS
        );

        return '(sm.extra_metadata IS NULL OR JSON_TYPE(sm.extra_metadata) <> \'OBJECT\' OR ('
            . implode(' AND ', $checks) . '))';
    }

    private static function intList(array $ids): string
    {
        $ids = array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));

        return implode(',', $ids);
    }

    private static function number(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }
}
