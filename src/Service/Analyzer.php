<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Service;

use RuntimeException;

/**
 * Computes the cue points and the gain of one audio file.
 *
 * Port of Liquidsoap's internal AutoCue (autocue.internal.implementation in
 * src/libs/autocue.liq), with the choices made for this plugin:
 *  - EBU R128 loudness measured by FFmpeg every 100 ms (same filter as Liquidsoap);
 *  - thresholds relative to the integrated loudness of the track;
 *  - precise cue_in: on the last silent measure before the music, without Liquidsoap's
 *    0.1 s margin, 0.2 s fade-in and "ignore super short cue in" rule (fade_in = 0);
 *  - no "sustained ending" detection;
 *  - no fade at all when no crossfade point is found (Liquidsoap would fade over the whole
 *    track and keep only max_overlap seconds);
 *  - amplify = -14 LUFS - integrated loudness, rounded to 0.1 dB.
 */
final class Analyzer
{
    private const int FFMPEG_TIMEOUT = 300;

    /** Start time of the first 100 ms frame whose 400 ms loudness window is complete. */
    private const float FIRST_FULL_WINDOW = 0.29;

    private ?string $ffmpeg = null;
    private ?string $ffprobe = null;
    private ?array $niceWrapper = null;

    /**
     * @return array{values: array<string, float>, lufs: float, duration: float, cross_found: bool}
     */
    public function analyse(string $file, array $settings, float $maxOverlap, ?float $knownLength = null): array
    {
        $duration = $this->duration($file) ?? $knownLength;
        if ($duration === null || $duration <= 0.0) {
            throw new RuntimeException('Durée du fichier illisible.');
        }

        $result = Shell::run([
            ...$this->wrapper(),
            $this->ffmpeg(),
            '-nostdin', '-hide_banner', '-v', 'error', '-threads', '1',
            '-i', $file,
            '-map', '0:a:0', '-vn',
            '-af', 'ebur128=metadata=1,asetpts=N/SR/TB,ametadata=mode=print:file=-',
            '-f', 'null', '-',
        ], self::FFMPEG_TIMEOUT);
        if ($result['code'] !== 0) {
            throw new RuntimeException('FFmpeg n\'a pas pu lire le fichier : ' . self::lastLine($result['err']));
        }

        $computed = self::compute(self::parseFrames($result['out']), $duration, $settings, $maxOverlap);

        return [
            'values' => self::toValues($computed, $knownLength),
            'lufs' => $computed['lufs'],
            'duration' => $duration,
            'cross_found' => $computed['cross_found'],
        ];
    }

    /**
     * Parses the output of FFmpeg's "ametadata=mode=print": one entry per 100 ms frame.
     *
     * @return list<array{t: float, M: ?float, I: ?float}>
     */
    public static function parseFrames(string $output): array
    {
        $frames = [];
        $current = -1;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_starts_with($line, 'frame:')) {
                if (preg_match('/pts_time:\s*(\S+)/', $line, $m)) {
                    $frames[] = ['t' => (float)$m[1], 'M' => null, 'I' => null];
                    $current++;
                }
                continue;
            }
            if ($current < 0) {
                continue;
            }
            if (str_starts_with($line, 'lavfi.r128.M=')) {
                $frames[$current]['M'] = self::level(substr($line, 13));
            } elseif (str_starts_with($line, 'lavfi.r128.I=')) {
                $frames[$current]['I'] = self::level(substr($line, 13));
            }
        }

        return $frames;
    }

    /**
     * The analysis itself (no I/O).
     *
     * @param list<array{t: float, M: ?float, I: ?float}> $frames
     *
     * @return array{cue_in: float, cue_out: float, fade_in: float, fade_out: float,
     *     cross_start_next: float, amplify: float, lufs: float, cross_found: bool}
     */
    public static function compute(array $frames, float $duration, array $settings, float $maxOverlap): array
    {
        $count = count($frames);
        if ($count < 2) {
            throw new RuntimeException('Analyse impossible : fichier trop court ou illisible.');
        }

        // Integrated loudness: second-to-last frame, the last one carrying loudness data (as Liquidsoap).
        $lufs = null;
        for ($i = $count - 2; $i >= 0 && $lufs === null; $i--) {
            $lufs = $frames[$i]['I'];
        }
        if ($lufs === null || $lufs <= -69.9) {
            throw new RuntimeException('Fichier silencieux : sonie impossible à mesurer.');
        }

        $cueInThreshold = $lufs + (float)$settings['cue_in_threshold'];
        $cueOutThreshold = $lufs + (float)$settings['cue_out_threshold'];
        $crossThreshold = $lufs + (float)$settings['cross_threshold'];

        // Cue in (forward): the last measure before the first one above the threshold.
        // The momentary loudness uses a 400 ms window: FFmpeg reports a placeholder (-120.7) for
        // the first three frames, whose window is not full yet. They are skipped, otherwise a track
        // that starts right away would get cue_in = 0.2 and lose its first 0.2 s. (Liquidsoap hides
        // this with its "ignore cue in <= 0.2 s" rule, which the precise cue_in does not use.)
        $cueIn = 0.0;
        $lastTs = 0.0;
        $found = false;
        foreach ($frames as $frame) {
            if ($frame['M'] === null || $frame['t'] < self::FIRST_FULL_WINDOW) {
                continue;
            }
            if ($frame['M'] > $cueInThreshold) {
                $cueIn = $lastTs;
                $found = true;
                break;
            }
            $lastTs = $frame['t'];
        }
        if (!$found) {
            throw new RuntimeException('Aucun son au-dessus du seuil de début : vérifiez le fichier.');
        }

        // Cue out and crossfade point (backward), exactly as Liquidsoap.
        $cueOut = 0.0;
        $crossCue = 0.0;
        $crossFound = false;
        $cueFound = false;
        $lastTs = 0.0;
        for ($i = $count - 1; $i >= 0; $i--) {
            $frame = $frames[$i];
            if ($frame['M'] === null) {
                continue;
            }
            if ($frame['M'] > $cueOutThreshold && !$cueFound) {
                $cueOut = $lastTs;
                $cueFound = true;
            } elseif ($frame['M'] > $crossThreshold) {
                $crossCue = $lastTs;
                $crossFound = true;
                break;
            }
            $lastTs = $frame['t'];
        }

        if ($cueOut == 0.0) {
            $cueOut = $duration;
        }
        if (!$crossFound) {
            $crossCue = $cueOut;
        }

        $fadeOut = 0.0;
        if ($crossCue + 0.1 < $cueOut) {
            $fadeOut = $cueOut - $crossCue;
        }
        if ($maxOverlap < $fadeOut) {
            $cueOut -= $fadeOut - $maxOverlap;
            $fadeOut = $maxOverlap;
        }

        return [
            'cue_in' => $cueIn,
            'cue_out' => $cueOut,
            'fade_in' => 0.0,
            'fade_out' => $fadeOut,
            'cross_start_next' => $cueOut - $fadeOut,
            'amplify' => Settings::TARGET_LUFS - $lufs,
            'lufs' => $lufs,
            'cross_found' => $crossFound,
        ];
    }

    /**
     * Values written in AzuraCast's custom cue points (StationMediaMetadata), rounded and made
     * consistent with the duration AzuraCast knows (it ignores a cue_out beyond that duration).
     *
     * @return array{cue_in: float, cue_out: float, fade_in: float, fade_out: float,
     *     cross_start_next: float, amplify: float}
     */
    public static function toValues(array $computed, ?float $knownLength): array
    {
        $cueIn = round(max(0.0, $computed['cue_in']), 2);
        $cueOut = $computed['cue_out'];
        $fadeOut = $computed['fade_out'];
        if ($knownLength !== null && $knownLength > 0 && $cueOut > $knownLength) {
            $fadeOut = max(0.0, $fadeOut - ($cueOut - $knownLength));
            $cueOut = $knownLength;
        }
        $cueOut = round($cueOut, 2);
        $fadeOut = round(min($fadeOut, max(0.0, $cueOut - $cueIn)), 2);
        if ($cueOut - $cueIn < 1.0) {
            throw new RuntimeException('Moins d\'une seconde de son entre le début et la fin : valeurs non enregistrées.');
        }

        return [
            'cue_in' => $cueIn,
            'cue_out' => $cueOut,
            'fade_in' => 0.0,
            'fade_out' => $fadeOut,
            'cross_start_next' => round($cueOut - $fadeOut, 2),
            'amplify' => round($computed['amplify'], 1),
        ];
    }

    private function duration(string $file): ?float
    {
        $result = Shell::run([
            $this->ffprobe(), '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $file,
        ], 60);
        $value = trim($result['out']);

        return $result['code'] === 0 && is_numeric($value) && (float)$value > 0 ? (float)$value : null;
    }

    private static function level(string $value): ?float
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return null; // "nan", "-inf"…: ignored, as Liquidsoap does
        }
        $level = (float)$value;

        return is_finite($level) ? $level : null;
    }

    private function ffmpeg(): string
    {
        return $this->ffmpeg ??= Shell::which('ffmpeg') ?? throw new RuntimeException('ffmpeg est introuvable.');
    }

    private function ffprobe(): string
    {
        return $this->ffprobe ??= Shell::which('ffprobe') ?? throw new RuntimeException('ffprobe est introuvable.');
    }

    /**
     * Low CPU and disk priority, so that the analysis never competes with Liquidsoap.
     *
     * @return string[]
     */
    private function wrapper(): array
    {
        if ($this->niceWrapper === null) {
            $this->niceWrapper = [];
            // Each wrapper is kept only if it works here (some containers refuse I/O priorities).
            $candidates = [['ionice', '-c', '2', '-n', '7'], ['nice', '-n', '15']];
            foreach ($candidates as $candidate) {
                $args = $candidate;
                $path = Shell::which(array_shift($args));
                if ($path === null) {
                    continue;
                }
                $test = Shell::run([$path, ...$args, 'true'], 10);
                if ($test['code'] === 0) {
                    $this->niceWrapper = [...$this->niceWrapper, $path, ...$args];
                }
            }
        }

        return $this->niceWrapper;
    }

    private static function lastLine(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));

        return $lines === [] ? 'erreur inconnue' : mb_substr(end($lines), 0, 300);
    }
}
