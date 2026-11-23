<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Service;

use App\Cache\MediaListCache;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Repository\StorageLocationRepository;
use App\Entity\Station;
use App\Entity\StorageLocation;
use App\Environment;
use App\Flysystem\ExtendedFilesystemInterface;
use App\MessageQueue\QueueManagerInterface;
use App\MessageQueue\QueueNames;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * One pass of the background processing (run every minute by AzuraCast's synchronization).
 *
 * Order of the checks, so as never to interfere with AzuraCast's own processing:
 *  1. nothing is done while AzuraCast's "media" queue still holds work;
 *  2. a file is taken only 60 s after AzuraCast has processed it (its tags, and so its own cue
 *     values if it has any, are then in the database);
 *  3. with remote storage (S3…), the file must be complete on the storage: AzuraCast saves the
 *     database record of an upload before it has finished sending the file;
 *  4. the file is copied to a temporary file of the plugin's own (never AzuraCast's shared
 *     temporary copy, which Liquidsoap may be using), analysed, then the copy is deleted;
 *  5. the values are written only if the file was not processed again meanwhile and if its
 *     values are still empty.
 */
final class Processor
{
    /** Time budget of one pass (the pass runs every minute). */
    public const int BUDGET_SECONDS = 50;

    /** A failed file is retried after this delay, at most MAX_ATTEMPTS times. */
    public const int RETRY_DELAY = 86400;
    public const int MAX_ATTEMPTS = 3;

    /** How long to wait for a file that is not (completely) on the storage yet. */
    private const int WAIT_MISSING = 3600;
    private const int WAIT_INCOMPLETE = 900;

    private const string TEMP_PREFIX = 'traitement-fichiers-';

    private readonly MediaFinder $finder;

    private readonly Sql $sql;

    public function __construct(
        private readonly ReloadableEntityManagerInterface $em,
        private readonly StorageLocationRepository $storageLocationRepo,
        private readonly QueueManagerInterface $queueManager,
        private readonly MediaListCache $mediaListCache,
        private readonly Environment $environment,
        private readonly LoggerInterface $logger,
        private readonly Analyzer $analyzer,
        private readonly Store $store,
        ?Sql $sql = null
    ) {
        // AzuraCast's container cannot choose an implementation of the interface: its own
        // Doctrine connection is used. The tests pass their own.
        $this->sql = $sql ?? new DbalSql($em);
        $this->finder = new MediaFinder($this->sql);
    }

    public function finder(): MediaFinder
    {
        return $this->finder;
    }

    /**
     * @param ?callable(string): void $report Progress messages (console command).
     *
     * @return array{analysed: int, failed: int, waiting: int, skipped: string}
     */
    public function run(int $budget = self::BUDGET_SECONDS, ?callable $report = null): array
    {
        $report ??= static function (string $message): void {
        };
        $stats = ['analysed' => 0, 'failed' => 0, 'waiting' => 0, 'skipped' => ''];

        $queued = $this->queueManager->getQueueCount(QueueNames::SlowMedia);
        if ($queued > 0) {
            $stats['skipped'] = sprintf('AzuraCast traite encore %d fichier(s) : rien à faire pour l\'instant.', $queued);
            $report($stats['skipped']);

            return $stats;
        }

        $this->cleanTemporaryFiles();
        $deadline = time() + $budget;

        foreach ($this->stationsByStorage() as $station) {
            if (time() >= $deadline) {
                break;
            }
            try {
                $this->processStation($station, $deadline, $stats, $report);
            } catch (Throwable $e) {
                $this->logger->error('Traitement Fichiers : ' . $e->getMessage(), ['station' => $station->id, 'exception' => $e]);
                $report('Erreur : ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Enabled stations whose setting is on, one per media storage location (when several
     * stations share their media, the one with the lowest ID decides).
     *
     * @return Station[]
     */
    public function stationsByStorage(): array
    {
        $rows = $this->sql->fetchAll('SELECT id FROM station WHERE is_enabled = 1 ORDER BY id ASC');
        $stations = [];
        foreach ($rows as $row) {
            $station = $this->em->find(Station::class, (int)$row['id']);
            if (!$station instanceof Station) {
                continue;
            }
            try {
                $enabled = $this->store->readSettings($station->getRadioConfigDir())['enabled'];
            } catch (Throwable $e) {
                $this->logger->error('Traitement Fichiers : ' . $e->getMessage(), ['station' => $station->id]);
                continue;
            }
            if (!$enabled) {
                continue;
            }
            $locationId = (int)$station->media_storage_location->id;
            $stations[$locationId] ??= $station;
        }

        return array_values($stations);
    }

    /** First enabled station (lowest ID) sharing this station's media, if it is not this one. */
    public function settingsOwner(Station $station): ?Station
    {
        foreach ($this->stationsByStorage() as $owner) {
            if ((int)$owner->media_storage_location->id === (int)$station->media_storage_location->id) {
                return $owner;
            }
        }

        return null;
    }

    /**
     * Maximum fade-out: the crossfade duration of the station's AutoDJ tab, or 6 s (Liquidsoap's
     * own default) when the crossfade is off. The stored crossfade method is read directly:
     * AzuraCast reports the crossfade as disabled while its AutoCue is on (and hides the field),
     * but uses it again as soon as AutoCue is turned off.
     */
    public static function maxOverlap(Station $station): float
    {
        return self::hasCrossfade($station)
            ? (float)$station->backend_config->crossfade
            : Settings::DEFAULT_MAX_OVERLAP;
    }

    public static function hasCrossfade(Station $station): bool
    {
        $config = $station->backend_config;

        return (string)$config->crossfade_type !== 'none' && (float)$config->crossfade > 0;
    }

    /** @return int[] Files left aside because they failed recently or too often. */
    public static function blockedIds(array $state, int $now): array
    {
        $ids = [];
        foreach ($state['failures'] as $id => $failure) {
            if ($failure['count'] >= self::MAX_ATTEMPTS || $failure['next_at'] > $now) {
                $ids[] = (int)$id;
            }
        }

        return $ids;
    }

    private function processStation(Station $station, int $deadline, array &$stats, callable $report): void
    {
        $configDir = $station->getRadioConfigDir();
        $settings = $this->store->readSettings($configDir);
        $state = $this->store->readState($configDir);
        $storageLocation = $station->media_storage_location;
        $locationId = (int)$storageLocation->id;
        $maxOverlap = self::maxOverlap($station);
        $now = time();

        // A failure is forgotten when the file was deleted, processed again by AzuraCast (new
        // mtime) or given values in the meantime.
        $state = $this->forgetObsoleteFailures($configDir, $state);

        $candidates = $this->finder->candidates(
            $locationId,
            $settings,
            $state['backfill'],
            $now,
            self::blockedIds($state, $now)
        );
        if ($candidates === []) {
            if ($state['backfill']) {
                $this->store->updateState($configDir, static function (array $state): array {
                    $state['backfill'] = false;

                    return $state;
                });
                $report($station->name . ' : analyse des fichiers existants terminée.');
                $this->logger->info('Traitement Fichiers : analyse des fichiers existants terminée.', ['station' => $station->id]);
            }

            return;
        }

        $fs = $this->storageLocationRepo->getAdapter($storageLocation)->getFilesystem();
        $isLocal = $storageLocation->adapter->isLocal();

        foreach ($candidates as $media) {
            if (time() >= $deadline) {
                break;
            }
            $temporary = null;
            try {
                $failure = $state['failures'][(string)$media['id']] ?? null;

                $file = $this->localFile($fs, $isLocal, $media, $temporary);
                if ($file === null) {
                    $stats['waiting']++;
                    $report('En attente (fichier pas encore complet sur le stockage) : ' . $media['path']);
                    continue;
                }

                $started = microtime(true);
                $result = $this->analyzer->analyse($file, $settings, $maxOverlap, $media['length']);
                $written = $this->finder->write($media['id'], $media['mtime'], $result['values']);

                if ($failure !== null) {
                    $state = $this->store->updateState($configDir, static function (array $state) use ($media): array {
                        unset($state['failures'][(string)$media['id']]);

                        return $state;
                    });
                }

                if ($written) {
                    $stats['analysed']++;
                    $this->mediaListCache->clearCache($storageLocation);
                    $message = sprintf(
                        '%s : %s (%.1f LUFS%s, %.1f s)',
                        $media['path'],
                        self::describe($result['values']),
                        $result['lufs'],
                        $result['cross_found'] ? '' : ', aucun point de fondu trouvé',
                        microtime(true) - $started
                    );
                    $report('Analysé : ' . $message);
                    $this->logger->info('Traitement Fichiers : ' . $message, ['station' => $station->id, 'media' => $media['id']]);
                } else {
                    $report('Non enregistré (fichier modifié ou valeurs saisies entre-temps) : ' . $media['path']);
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $count = ($failure['count'] ?? 0) + 1;
                $message = $e->getMessage();
                $state = $this->store->updateState(
                    $configDir,
                    static function (array $state) use ($media, $count, $message, $now): array {
                        $state['failures'][(string)$media['id']] = [
                            'mtime' => $media['mtime'],
                            'count' => $count,
                            'next_at' => $now + self::RETRY_DELAY,
                            'error' => $message,
                            'path' => $media['path'],
                        ];

                        return $state;
                    }
                );
                $final = $count >= self::MAX_ATTEMPTS;
                $text = sprintf(
                    'Traitement Fichiers : analyse impossible de %s (essai %d/%d%s) : %s',
                    $media['path'],
                    $count,
                    self::MAX_ATTEMPTS,
                    $final ? ', abandonné' : ', nouvel essai dans 24 h',
                    $message
                );
                $report($text);
                $this->logger->warning($text, ['station' => $station->id, 'media' => $media['id']]);
            } finally {
                if ($temporary !== null) {
                    @unlink($temporary);
                }
            }
        }
    }

    /**
     * Path of a local copy of the file, or null if it is not completely on the storage yet.
     *
     * @param ?string $temporary Set to the temporary copy to delete afterwards.
     */
    private function localFile(ExtendedFilesystemInterface $fs, bool $isLocal, array $media, ?string &$temporary): ?string
    {
        $age = time() - $media['mtime'];
        if (!$fs->fileExists($media['path'])) {
            if ($age < self::WAIT_MISSING) {
                return null;
            }
            throw new RuntimeException('Fichier introuvable sur le stockage.');
        }

        if ($isLocal) {
            return $fs->getLocalPath($media['path']);
        }

        if ($media['size'] > 0 && $age < self::WAIT_INCOMPLETE && $fs->fileSize($media['path']) !== $media['size']) {
            return null; // upload to the storage not finished yet
        }

        $temporary = $this->download($fs, $media['path']);

        return $temporary;
    }

    /**
     * Calls $action with a local path of the file: the file itself on local storage, otherwise a
     * temporary copy of the plugin's own, deleted afterwards.
     *
     * @template T
     * @param callable(string): T $action
     * @return T
     */
    public function withFile(StorageLocation $storageLocation, string $path, callable $action): mixed
    {
        $fs = $this->storageLocationRepo->getAdapter($storageLocation)->getFilesystem();
        if ($storageLocation->adapter->isLocal()) {
            return $action($fs->getLocalPath($path));
        }
        $temporary = $this->download($fs, $path);
        try {
            return $action($temporary);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Copies a file of a remote storage to a temporary file with a unique name. AzuraCast's own
     * withLocalFile() is not used: its temporary name is the same for everybody, and Liquidsoap
     * may be using (then deleting) that very copy while it prepares the next track.
     */
    private function download(ExtendedFilesystemInterface $fs, string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $temporary = $this->temporaryDir() . '/' . self::TEMP_PREFIX . bin2hex(random_bytes(8))
            . ($extension !== '' && ctype_alnum($extension) ? '.' . $extension : '');

        $target = fopen($temporary, 'wb');
        if ($target === false) {
            throw new RuntimeException('Impossible de créer le fichier temporaire ' . $temporary);
        }
        $source = null;
        try {
            $source = $fs->readStream($path);
            if (stream_copy_to_stream($source, $target) === false) {
                throw new RuntimeException('Téléchargement interrompu.');
            }
        } catch (Throwable $e) {
            fclose($target);
            @unlink($temporary);
            throw $e;
        } finally {
            if (is_resource($target)) {
                fclose($target);
            }
            if (is_resource($source)) {
                fclose($source);
            }
        }

        return $temporary;
    }

    /** Failures of files that were deleted, processed again or given values are forgotten. */
    private function forgetObsoleteFailures(string $configDir, array $state): array
    {
        if ($state['failures'] === []) {
            return $state;
        }
        $ids = array_map('intval', array_keys($state['failures']));
        $rows = $this->sql->fetchAll(
            'SELECT id, mtime, extra_metadata FROM station_media WHERE id IN (' . implode(',', $ids) . ')'
        );
        $current = [];
        foreach ($rows as $row) {
            if (MediaFinder::isEmpty($row['extra_metadata'] ?? null)) {
                $current[(string)$row['id']] = (int)$row['mtime'];
            }
        }
        $obsolete = [];
        foreach ($state['failures'] as $id => $failure) {
            if (($current[(string)$id] ?? null) !== $failure['mtime']) {
                $obsolete[] = (string)$id;
            }
        }
        if ($obsolete === []) {
            return $state;
        }

        return $this->store->updateState($configDir, static function (array $state) use ($obsolete): array {
            foreach ($obsolete as $id) {
                unset($state['failures'][$id]);
            }

            return $state;
        });
    }

    private function temporaryDir(): string
    {
        $dir = $this->environment->getTempDirectory();
        if (!is_dir($dir) || !is_writable($dir)) {
            $dir = sys_get_temp_dir();
        }

        return rtrim($dir, '/');
    }

    /** Leftovers of an interrupted pass (container restart…). */
    private function cleanTemporaryFiles(): void
    {
        foreach (glob($this->temporaryDir() . '/' . self::TEMP_PREFIX . '*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
    }

    /** @param array<string, float> $values */
    public static function describe(array $values): string
    {
        $parts = [];
        foreach ($values as $key => $value) {
            $parts[] = $key . '=' . rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
        }

        return implode(', ', $parts);
    }
}
