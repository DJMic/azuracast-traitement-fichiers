<?php

declare(strict_types=1);

// Standalone tests: php tests/run.php
//  - settings and analysis: always (the analysis needs ffmpeg/ffprobe);
//  - database and background processing: when a MariaDB/MySQL test database is available
//    (TF_TEST_DSN, TF_TEST_USER, TF_TEST_PASSWORD; default: mysql:host=127.0.0.1;dbname=tf, tf/tf).
//    The tables of that database are dropped and recreated.

require __DIR__ . '/stubs.php';
foreach (['Plugin', 'Shell', 'Settings', 'Store', 'Analyzer', 'Sql', 'MediaFinder', 'DbalSql', 'Processor'] as $class) {
    require __DIR__ . '/../src/Service/' . $class . '.php';
}

use App\Cache\MediaListCache;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Repository\StorageLocationRepository;
use App\Entity\Station;
use App\Entity\StationBackendConfiguration;
use App\Entity\StorageLocation;
use App\Entity\StorageLocationAdapterKind;
use App\Environment;
use App\Flysystem\ExtendedFilesystemInterface;
use App\MessageQueue\QueueManagerInterface;
use App\MessageQueue\QueueNames;
use Plugin\AzuracastTraitementFichiers\Service\Analyzer;
use Plugin\AzuracastTraitementFichiers\Service\MediaFinder;
use Plugin\AzuracastTraitementFichiers\Service\Processor;
use Plugin\AzuracastTraitementFichiers\Service\Settings;
use Plugin\AzuracastTraitementFichiers\Service\Shell;
use Plugin\AzuracastTraitementFichiers\Service\Sql;
use Plugin\AzuracastTraitementFichiers\Service\Store;
use Psr\Log\LoggerInterface;

$failures = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual !== $expected) {
        $failures++;
        echo "ÉCHEC  $label\n  attendu : " . var_export($expected, true) . "\n  obtenu  : " . var_export($actual, true) . "\n";
    } else {
        echo "ok     $label\n";
    }
}
function near(string $label, float $actual, float $expected, float $tolerance): void
{
    check($label . " (≈ $expected ± $tolerance, obtenu $actual)", abs($actual - $expected) <= $tolerance, true);
}
function throws(string $label, callable $fn, string $contains = ''): void
{
    try {
        $fn();
        check($label, 'pas d\'exception', 'exception');
    } catch (Throwable $e) {
        check($label . ($contains !== '' ? " [$contains]" : ''), $contains === '' || str_contains($e->getMessage(), $contains), true);
    }
}

$work = sys_get_temp_dir() . '/tf-tests-' . getmypid();
@mkdir($work, 0777, true);
register_shutdown_function(static function () use ($work): void {
    exec('rm -rf ' . escapeshellarg($work));
});

function ffmpeg(string $output, string ...$args): string
{
    global $work;
    $file = $work . '/' . $output;
    $result = Shell::run(['ffmpeg', '-v', 'error', '-y', ...$args, $file], 60);
    if ($result['code'] !== 0) {
        throw new RuntimeException('ffmpeg : ' . $result['err']);
    }

    return $file;
}

/** Pink noise of $seconds after $silence seconds of silence. */
function noise(string $name, float $silence, float $seconds, string $extra = ''): string
{
    $noise = "anoisesrc=d=$seconds:c=pink:a=0.2:r=44100";
    if ($silence <= 0) {
        return ffmpeg($name, '-f', 'lavfi', '-i', $noise . $extra, '-ac', '2');
    }

    return ffmpeg(
        $name,
        '-f', 'lavfi', '-i', "anullsrc=r=44100:cl=stereo:d=$silence",
        '-f', 'lavfi', '-i', $noise,
        '-filter_complex', "[1]aformat=channel_layouts=stereo$extra" . "[s];[0][s]concat=n=2:v=0:a=1"
    );
}

// --- Settings ---------------------------------------------------------------------------------
$defaults = Settings::defaults();
check('défauts : -28 / -28 / -5, désactivé', [$defaults['cue_in_threshold'], $defaults['cue_out_threshold'], $defaults['cross_threshold'], $defaults['enabled']], [-28.0, -28.0, -5.0, false]);

$saved = Settings::fromInput(
    ['enabled' => true, 'cue_in_threshold' => '-30,5', 'cue_out_threshold' => -40, 'cross_threshold' => '-6', 'excluded_playlists' => [3, '7', 99, 'x']],
    $defaults,
    [3, 7, 8],
    1000
);
check('saisie : virgule acceptée', $saved['cue_in_threshold'], -30.5);
check('saisie : playlists inconnues retirées', $saved['excluded_playlists'], [3, 7]);
check('première activation mémorisée', $saved['activated_at'], 1000);
$again = Settings::fromInput(['enabled' => false, 'cue_in_threshold' => -28, 'cue_out_threshold' => -28, 'cross_threshold' => -5], $saved, [], 2000);
$again = Settings::fromInput(['enabled' => true, 'cue_in_threshold' => -28, 'cue_out_threshold' => -28, 'cross_threshold' => -5], $again, [], 3000);
check('réactivation : la date de première activation est gardée', $again['activated_at'], 1000);
throws('seuil hors limites refusé', fn() => Settings::fromInput(['cue_in_threshold' => -80, 'cue_out_threshold' => -28, 'cross_threshold' => -5], $defaults, [], 1), 'entre');
throws('seuil non numérique refusé', fn() => Settings::fromInput(['cue_in_threshold' => 'abc', 'cue_out_threshold' => -28, 'cross_threshold' => -5], $defaults, [], 1), 'nombre');
throws('seuil de fondu sous le seuil de fin refusé', fn() => Settings::fromInput(['cue_in_threshold' => -28, 'cue_out_threshold' => -28, 'cross_threshold' => -30], $defaults, [], 1), 'plus élevé');
check('fichier abîmé : valeurs par défaut', Settings::withDefaults(['cue_in_threshold' => 'n', 'excluded_playlists' => 'x'])['cue_in_threshold'], -28.0);

// --- Store ------------------------------------------------------------------------------------
$store = new Store();
$configDir = $work . '/station1/config';
check('réglages absents : défauts', $store->readSettings($configDir), $defaults);
$store->writeSettings($configDir, $saved);
check('réglages relus', $store->readSettings($configDir), $saved);
$store->updateState($configDir, fn(array $s): array => ['backfill' => true, 'failures' => ['12' => ['mtime' => 5, 'count' => 1, 'next_at' => 9, 'error' => 'x']]] + $s);
check('état relu', $store->readState($configDir)['failures']['12']['count'], 1);
check('blocage : échec récent', Processor::blockedIds($store->readState($configDir), 5), [12]);
check('blocage : délai passé', Processor::blockedIds($store->readState($configDir), 10), []);

// --- Analysis ---------------------------------------------------------------------------------
$settings = Settings::defaults();
$analyzer = new Analyzer();

// Precise cue_in, whatever the leading silence (the first 3 frames of FFmpeg are placeholders).
foreach ([[0.0, 0.0], [0.15, 0.0], [0.5, 0.4], [1.0, 0.9], [2.0, 1.9]] as [$silence, $expected]) {
    $file = noise("silence-$silence.mp3", $silence, 20);
    $result = $analyzer->analyse($file, $settings, 4.0, null);
    near("cue_in précis après $silence s de silence", $result['values']['cue_in'], $expected, 0.11);
    check("cue_in après $silence s : jamais après le début du son", $result['values']['cue_in'] <= max(0.0, $silence), true);
    check("fade_in = 0 ($silence s)", $result['values']['fade_in'], 0.0);
}

// Loudness and gain: -14 LUFS target, one decimal.
$file = noise('gain.flac', 0, 30);
$result = $analyzer->analyse($file, $settings, 4.0, null);
$reference = Shell::run(['ffmpeg', '-nostdin', '-hide_banner', '-i', $file, '-af', 'ebur128', '-f', 'null', '-'], 60)['err'];
preg_match_all('/I:\s+(-?[\d.]+) LUFS/', $reference, $m);
$integrated = (float)end($m[1]);
near('sonie mesurée = sonie intégrée EBU R128 de FFmpeg', $result['lufs'], $integrated, 0.15);
check('amplify = -14 - sonie, arrondi à 0,1 dB', $result['values']['amplify'], round(-14.0 - $result['lufs'], 1));
check('amplify : un chiffre après la virgule', round($result['values']['amplify'], 1) === $result['values']['amplify'], true);

// Fade-out: a long fade at the end, capped by the station's crossfade duration.
$file = noise('fade.mp3', 0.0, 40, ',afade=t=out:st=28:d=12');
$result = $analyzer->analyse($file, $settings, 4.0, null);
$v = $result['values'];
check('point de fondu trouvé', $result['cross_found'], true);
check('fade_out plafonné à 4 s', $v['fade_out'], 4.0);
near('cross_start_next = cue_out - fade_out', $v['cross_start_next'], $v['cue_out'] - $v['fade_out'], 0.011);
check('cue_out avant la fin du fichier', $v['cue_out'] < 40.0, true);
$wide = $analyzer->analyse($file, $settings, 30.0, null)['values'];
check('sans plafond, le fondu est plus long', $wide['fade_out'] > 4.0, true);

// Abrupt end: no fade.
$file = noise('abrupt.mp3', 0.0, 20);
$v = $analyzer->analyse($file, $settings, 4.0, null)['values'];
check('fin franche : cue_out ≈ fin du son', abs($v['cue_out'] - 20.0) < 0.5, true);
check('fin franche : cross_start_next ≤ cue_out', $v['cross_start_next'] <= $v['cue_out'], true);

// Duration known by AzuraCast shorter than the computed cue_out: cue_out is clamped.
$clamped = Analyzer::toValues(['cue_in' => 0.4, 'cue_out' => 200.6, 'fade_out' => 4.0, 'amplify' => -4.66], 200.0);
check('cue_out ramené à la durée connue d\'AzuraCast', [$clamped['cue_out'], $clamped['fade_out'], $clamped['cross_start_next']], [200.0, 3.4, 196.6]);
check('arrondis', [$clamped['cue_in'], $clamped['amplify']], [0.4, -4.7]);

// Silence and unreadable files.
throws('fichier silencieux refusé', fn() => $analyzer->analyse(ffmpeg('silent.mp3', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo:d=10'), $settings, 4.0, null), 'silencieux');
file_put_contents($work . '/broken.mp3', str_repeat('not audio', 1000));
throws('fichier illisible refusé', fn() => $analyzer->analyse($work . '/broken.mp3', $settings, 4.0, null));

// Same algorithm as Liquidsoap on a crafted frame list (100 ms frames, integrated -10 LUFS).
$frames = [];
for ($i = 0; $i < 100; $i++) {
    $t = round($i / 10, 1);
    $level = match (true) {
        $i < 3 => -120.691,  // FFmpeg placeholders
        $i < 12 => -70.0,    // silence until 1.2 s
        $i < 80 => -10.0,    // music
        $i < 90 => -25.0,    // fading: below cross (-15) but above cue_out (-38)
        default => -80.0,    // silence
    };
    $frames[] = ['t' => $t, 'M' => $level, 'I' => -10.0];
}
$computed = Analyzer::compute($frames, 10.0, $settings, 6.0);
check('liste construite : cue_in = mesure précédant le son', $computed['cue_in'], 1.1);
check('liste construite : cue_out = mesure suivant le dernier son', $computed['cue_out'], 9.0);
check('liste construite : point de fondu après la dernière mesure forte', $computed['cross_start_next'], 8.0);
check('liste construite : fade_out', $computed['fade_out'], 1.0);
check('liste construite : amplify', $computed['amplify'], -4.0);

// --- Database and background processing ---------------------------------------------------------
final class PdoSql implements Sql
{
    public function __construct(public PDO $pdo)
    {
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_values($params));

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_values($params));

        return $statement->rowCount();
    }

    public function ensureConnected(): void
    {
    }
}

try {
    $pdo = new PDO(
        getenv('TF_TEST_DSN') ?: 'mysql:host=127.0.0.1;dbname=tf;charset=utf8mb4',
        getenv('TF_TEST_USER') ?: 'tf',
        getenv('TF_TEST_PASSWORD') ?: 'tf',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    $pdo = null;
    echo "--     base de données de test indisponible : tests de la base ignorés (" . $e->getMessage() . ")\n";
}

if ($pdo !== null) {
    $sql = new PdoSql($pdo);
    // Same columns as AzuraCast's tables (only those the plugin uses).
    $pdo->exec('DROP TABLE IF EXISTS station_playlist_media, station_playlists, station_media, station');
    $pdo->exec('CREATE TABLE station (id INT PRIMARY KEY, is_enabled TINYINT(1) NOT NULL)');
    $pdo->exec('CREATE TABLE station_media (id INT AUTO_INCREMENT PRIMARY KEY, storage_location_id INT NOT NULL,
        path VARCHAR(500) NOT NULL, length DOUBLE NOT NULL, size BIGINT NOT NULL, mtime INT NOT NULL,
        uploaded_at INT NOT NULL, extra_metadata LONGTEXT NULL CHECK (extra_metadata IS NULL OR JSON_VALID(extra_metadata)))');
    $pdo->exec('CREATE TABLE station_playlist_media (id INT AUTO_INCREMENT PRIMARY KEY, media_id INT NOT NULL, playlist_id INT NOT NULL)');
    $pdo->exec('CREATE TABLE station_playlists (id INT AUTO_INCREMENT PRIMARY KEY, station_id INT NOT NULL, name VARCHAR(200) NOT NULL)');
    $pdo->exec('INSERT INTO station VALUES (1, 1), (2, 1), (3, 0)');
    $pdo->exec("INSERT INTO station_playlists (id, station_id, name) VALUES (40, 1, 'Jingles'), (5, 1, 'Hits'), (6, 2, 'Autre station')");

    $now = time();
    $activated = $now - 86400;
    $mediaDir = $work . '/media';
    @mkdir($mediaDir . '/MP3', 0777, true);
    $song = noise('song.mp3', 0.5, 20, ',afade=t=out:st=14:d=6');
    copy($song, $mediaDir . '/MP3/new.mp3');
    copy($song, $mediaDir . '/MP3/nulls.mp3');
    copy($song, $mediaDir . '/MP3/old.mp3');
    file_put_contents($mediaDir . '/MP3/broken.mp3', str_repeat('x', 5000));
    $nullsJson = json_encode(array_fill_keys(MediaFinder::FIELDS, null));
    $rows = [
        // path, length, size, mtime, uploaded_at, extra_metadata, location
        ['MP3/new.mp3', 20.5, filesize($song), $now - 120, $now - 130, null, 1],
        ['MP3/tagged.mp3', 20.5, 100, $now - 120, $now - 130, '{"cue_in":0.5}', 1],
        ['MP3/old.mp3', 20.5, filesize($song), $now - 9000, $activated - 100, null, 1],
        ['MP3/long.mp3', 600.0, 100, $now - 120, $now - 130, null, 1],
        ['MP3/fresh.mp3', 20.5, 100, $now - 10, $now - 10, null, 1],
        ['MP3/jingle.mp3', 5.0, 100, $now - 120, $now - 130, null, 1],
        ['MP3/nulls.mp3', 20.5, filesize($song), $now - 120, $now - 130, $nullsJson, 1],
        ['MP3/broken.mp3', 20.5, 5000, $now - 120, $now - 130, null, 1],
        ['MP3/amplify-only.mp3', 20.5, 100, $now - 120, $now - 130, '{"amplify":-3.2}', 1],
        ['S3/remote.mp3', 20.5, filesize($song), $now - 120, $now - 130, null, 2],
    ];
    $insert = $pdo->prepare('INSERT INTO station_media (path, length, size, mtime, uploaded_at, extra_metadata, storage_location_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ids = [];
    foreach ($rows as $row) {
        $insert->execute($row);
        $ids[$row[0]] = (int)$pdo->lastInsertId();
    }
    $pdo->exec('INSERT INTO station_playlist_media (media_id, playlist_id) VALUES (' . $ids['MP3/jingle.mp3'] . ', 40)');

    $finder = new MediaFinder($sql);
    check('playlists de la station (table station_playlists)', $finder->playlists(1), [['id' => 5, 'name' => 'Hits'], ['id' => 40, 'name' => 'Jingles']]);
    $settings = ['activated_at' => $activated, 'excluded_playlists' => [40]] + Settings::defaults();
    $paths = static fn(array $list): array => array_map(static fn(array $m): string => $m['path'], $list);

    check(
        'candidats : nouveaux fichiers sans valeurs (sauf longs, récents, exclus, déjà renseignés)',
        $paths($finder->candidates(1, $settings, false, $now)),
        ['MP3/new.mp3', 'MP3/nulls.mp3', 'MP3/broken.mp3']
    );
    check('candidats : fichiers existants inclus sur demande', in_array('MP3/old.mp3', $paths($finder->candidates(1, $settings, true, $now)), true), true);
    check('candidats : fichiers bloqués écartés', $paths($finder->candidates(1, $settings, false, $now, [$ids['MP3/broken.mp3']])), ['MP3/new.mp3', 'MP3/nulls.mp3']);
    check('comptage des nouveaux (récent inclus)', $finder->count(1, $settings, false), 4);
    check('isEmpty : toutes les valeurs nulles', MediaFinder::isEmpty($nullsJson), true);
    check('isEmpty : une seule valeur suffit à bloquer', MediaFinder::isEmpty('{"amplify":-3.2}'), false);
    check('isEmpty : 0 est une valeur', MediaFinder::isEmpty('{"fade_in":0}'), false);

    $values = ['cue_in' => 0.4, 'cue_out' => 19.9, 'fade_in' => 0.0, 'fade_out' => 2.1, 'cross_start_next' => 17.8, 'amplify' => 15.3];
    check('écriture refusée si le fichier a été retraité (mtime différent)', $finder->write($ids['MP3/new.mp3'], $now - 1, $values), false);
    check('écriture refusée si une valeur existe déjà', $finder->write($ids['MP3/tagged.mp3'], $now - 120, $values), false);
    check('fichier renseigné intact', $sql->fetchAll('SELECT extra_metadata FROM station_media WHERE id = ?', [$ids['MP3/tagged.mp3']])[0]['extra_metadata'], '{"cue_in":0.5}');

    // Background processing with a local storage (1) and a remote one (2, like S3).
    $localFs = new class($mediaDir) implements ExtendedFilesystemInterface {
        public function __construct(private string $root)
        {
        }

        public function fileExists(string $path): bool
        {
            return is_file($this->root . '/' . $path);
        }

        public function fileSize(string $path): int
        {
            return (int)filesize($this->root . '/' . $path);
        }

        public function readStream(string $path)
        {
            throw new LogicException('stockage local : pas de téléchargement attendu');
        }

        public function getLocalPath(string $path): string
        {
            return $this->root . '/' . $path;
        }
    };
    $remoteFs = new class($song) implements ExtendedFilesystemInterface {
        public int $reportedSize = 10; // upload in progress
        public int $downloads = 0;

        public function __construct(private string $source)
        {
        }

        public function fileExists(string $path): bool
        {
            return true;
        }

        public function fileSize(string $path): int
        {
            return $this->reportedSize;
        }

        public function readStream(string $path)
        {
            $this->downloads++;

            return fopen($this->source, 'rb');
        }

        public function getLocalPath(string $path): string
        {
            throw new LogicException('AzuraCast\'s shared temporary copy must not be used');
        }
    };

    $configs = [1 => $work . '/st1/config', 2 => $work . '/st2/config', 3 => $work . '/st3/config'];
    $stations = [
        1 => new Station(1, 'Locale', new StorageLocation(1, new StorageLocationAdapterKind(true)), new StationBackendConfiguration(4.0, 'normal', true), $configs[1]),
        2 => new Station(2, 'S3', new StorageLocation(2, new StorageLocationAdapterKind(false)), new StationBackendConfiguration(2.0, 'none'), $configs[2]),
        3 => new Station(3, 'Arrêtée', new StorageLocation(1, new StorageLocationAdapterKind(true)), new StationBackendConfiguration(), $configs[3]),
    ];
    foreach ([1, 2, 3] as $id) {
        $store->writeSettings($configs[$id], ['enabled' => true, 'activated_at' => $activated, 'excluded_playlists' => [40]] + Settings::defaults());
    }
    $em = new class($stations) implements ReloadableEntityManagerInterface {
        public function __construct(private array $stations)
        {
        }

        public function find(string $class, mixed $id): ?object
        {
            return $this->stations[(int)$id] ?? null;
        }
    };
    $queue = new class implements QueueManagerInterface {
        public int $count = 0;

        public function getQueueCount(QueueNames $queue): int
        {
            return $this->count;
        }
    };
    $logger = new class implements LoggerInterface {
        public array $lines = [];

        public function info(string|Stringable $message, array $context = []): void
        {
            $this->lines[] = 'info: ' . $message;
        }

        public function warning(string|Stringable $message, array $context = []): void
        {
            $this->lines[] = 'warning: ' . $message;
        }

        public function error(string|Stringable $message, array $context = []): void
        {
            $this->lines[] = 'error: ' . $message;
        }
    };
    $cache = new MediaListCache();
    $temp = $work . '/tmp';
    @mkdir($temp);
    $processor = new Processor(
        $em,
        new StorageLocationRepository([1 => $localFs, 2 => $remoteFs]),
        $queue,
        $cache,
        new Environment($temp),
        $logger,
        new Analyzer(),
        $store,
        $sql
    );
    $metadata = static function (string $path) use ($sql, $ids): ?array {
        $raw = $sql->fetchAll('SELECT extra_metadata FROM station_media WHERE id = ?', [$ids[$path]])[0]['extra_metadata'];

        return $raw === null ? null : json_decode($raw, true);
    };

    $queue->count = 2;
    $stats = $processor->run();
    check('file « media » d\'AzuraCast occupée : rien n\'est fait', [$stats['analysed'], $metadata('MP3/new.mp3')], [0, null]);

    $queue->count = 0;
    check('stations retenues : une par stockage, station arrêtée ignorée', array_map(static fn(Station $s): int => $s->id, $processor->stationsByStorage()), [1, 2]);
    $stats = $processor->run();
    check('1er passage : 2 analysés, 1 échec, 1 en attente (S3 incomplet)', [$stats['analysed'], $stats['failed'], $stats['waiting']], [2, 1, 1]);

    $new = $metadata('MP3/new.mp3');
    check('valeurs écrites : les six champs', array_keys(array_filter($new, static fn($v) => $v !== null)), ['cue_in', 'cue_out', 'fade_in', 'fade_out', 'cross_start_next', 'amplify']);
    near('cue_in précis (0,5 s de silence)', (float)$new['cue_in'], 0.4, 0.11);
    check('fade_out plafonné au fondu enchaîné de la station (4 s, même avec AutoCue activé)', (float)$new['fade_out'] <= 4.0, true);
    check('nombres JSON (pas de texte)', [is_string($new['cue_out']), is_string($new['amplify']), $new['amplify']], [false, false, $new['amplify']]);
    check('mtime inchangé (AzuraCast ne retraite pas le fichier)', (int)$sql->fetchAll('SELECT mtime FROM station_media WHERE id = ?', [$ids['MP3/new.mp3']])[0]['mtime'], $now - 120);
    check('valeurs nulles remplacées', $metadata('MP3/nulls.mp3')['cue_out'] !== null, true);
    check('fichier déjà renseigné intact', $metadata('MP3/tagged.mp3'), ['cue_in' => 0.5]);
    check('gain seul intact', $metadata('MP3/amplify-only.mp3'), ['amplify' => -3.2]);
    check('fichier long ignoré', $metadata('MP3/long.mp3'), null);
    check('fichier exclu ignoré', $metadata('MP3/jingle.mp3'), null);
    check('fichier existant ignoré sans demande', $metadata('MP3/old.mp3'), null);
    check('fichier tout juste traité par AzuraCast : attend', $metadata('MP3/fresh.mp3'), null);
    check('S3 incomplet : pas de téléchargement', $remoteFs->downloads, 0);
    check('échec mémorisé', $store->readState($configs[1])['failures'][(string)$ids['MP3/broken.mp3']]['count'] ?? null, 1);
    check('échec dans le journal', count(array_filter($logger->lines, static fn($l) => str_starts_with($l, 'warning:') && str_contains($l, 'broken.mp3'))), 1);
    check('cache de la liste des médias vidé', $cache->cleared >= 2, true);

    // Second pass: the S3 upload is complete; the broken file is not retried before 24 h.
    $remoteFs->reportedSize = filesize($song);
    $stats = $processor->run();
    check('2e passage : le fichier S3 est analysé, l\'échec n\'est pas retenté', [$stats['analysed'], $stats['failed']], [1, 0]);
    check('S3 : téléchargé une fois', $remoteFs->downloads, 1);
    check('S3 : fondu enchaîné désactivé → plafond de 6 s', (float)$metadata('S3/remote.mp3')['fade_out'] <= 6.0, true);
    check('S3 : copie temporaire supprimée', glob($temp . '/traitement-fichiers-*'), []);

    // AzuraCast processes the broken file again (new mtime): the failure is forgotten.
    $pdo->exec('UPDATE station_media SET mtime = mtime + 1 WHERE id = ' . $ids['MP3/broken.mp3']);
    $processor->run();
    check('fichier retraité par AzuraCast : nouvel essai immédiat', $store->readState($configs[1])['failures'][(string)$ids['MP3/broken.mp3']]['count'] ?? null, 1);
    check('nouvel échec avec le nouveau mtime', $store->readState($configs[1])['failures'][(string)$ids['MP3/broken.mp3']]['mtime'] ?? null, $now - 119);

    // The "existing files" pass.
    $store->updateState($configs[1], static fn(array $s): array => ['backfill' => true] + $s);
    $stats = $processor->run();
    check('fichiers existants : le fichier ancien est analysé', [$stats['analysed'], $metadata('MP3/old.mp3') !== null], [1, true]);
    $processor->run();
    check('fichiers existants : terminé automatiquement', $store->readState($configs[1])['backfill'], false);

    // Nothing left: a pass does nothing.
    $stats = $processor->run();
    check('plus rien à faire', [$stats['analysed'], $stats['failed']], [0, 0]);

    // Deleted file: its failure is forgotten.
    $pdo->exec('DELETE FROM station_media WHERE id = ' . $ids['MP3/broken.mp3']);
    $processor->run();
    check('fichier supprimé : échec oublié', $store->readState($configs[1])['failures'], []);
}

echo $failures === 0 ? "\nTous les tests sont passés.\n" : "\n$failures test(s) en échec.\n";
exit($failures === 0 ? 0 : 1);
