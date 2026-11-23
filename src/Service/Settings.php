<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Service;

use InvalidArgumentException;

/**
 * Settings of one station (traitement-fichiers.json in the station's config folder).
 *
 * The three thresholds work like those of Liquidsoap's internal AutoCue: they are relative
 * to the integrated loudness of each track (a track measured at -9 LUFS with a -28 dB
 * threshold uses -37 dB).
 */
final class Settings
{
    /** Loudness target for the "amplify" value. */
    public const float TARGET_LUFS = -14.0;

    /** Files longer than this (in seconds) are never analysed. */
    public const float MAX_DURATION = 480.0;

    /** Fade-out cap used when the station's crossfade is disabled (Liquidsoap's own default). */
    public const float DEFAULT_MAX_OVERLAP = 6.0;

    public const array LIMITS = [
        'cue_in_threshold' => [-60.0, -1.0],
        'cue_out_threshold' => [-60.0, -1.0],
        'cross_threshold' => [-40.0, 0.0],
    ];

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'cue_in_threshold' => -28.0,
            'cue_out_threshold' => -28.0,
            'cross_threshold' => -5.0,
            'excluded_playlists' => [],
            // Time of the first activation: only files added since then are analysed automatically.
            'activated_at' => 0,
        ];
    }

    /** Fills missing or invalid values of a stored file with the defaults. */
    public static function withDefaults(array $data): array
    {
        $settings = self::defaults();
        $settings['enabled'] = (bool)($data['enabled'] ?? false);
        foreach (self::LIMITS as $key => [$min, $max]) {
            $value = $data[$key] ?? null;
            if (is_numeric($value) && (float)$value >= $min && (float)$value <= $max) {
                $settings[$key] = round((float)$value, 1);
            }
        }
        $settings['excluded_playlists'] = self::ids($data['excluded_playlists'] ?? []);
        $settings['activated_at'] = max(0, (int)($data['activated_at'] ?? 0));

        return $settings;
    }

    /**
     * Validates the values sent by the settings tab.
     *
     * @param int[] $playlistIds Playlists of the station (others are dropped).
     */
    public static function fromInput(array $input, array $current, array $playlistIds, int $now): array
    {
        $settings = self::withDefaults($current);
        $settings['enabled'] = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOL);

        $labels = [
            'cue_in_threshold' => 'Seuil de début (cue_in)',
            'cue_out_threshold' => 'Seuil de fin (cue_out)',
            'cross_threshold' => 'Seuil de fondu enchaîné (cross)',
        ];
        foreach (self::LIMITS as $key => [$min, $max]) {
            $raw = $input[$key] ?? null;
            $raw = is_string($raw) ? str_replace(',', '.', trim($raw)) : $raw;
            if (!is_numeric($raw)) {
                throw new InvalidArgumentException($labels[$key] . ' : saisissez un nombre.');
            }
            $value = round((float)$raw, 1);
            if ($value < $min || $value > $max) {
                throw new InvalidArgumentException(sprintf(
                    '%s : la valeur doit être comprise entre %s et %s dB.',
                    $labels[$key],
                    self::number($min),
                    self::number($max)
                ));
            }
            $settings[$key] = $value;
        }
        if ($settings['cross_threshold'] <= $settings['cue_out_threshold']) {
            throw new InvalidArgumentException(
                'Le seuil de fondu enchaîné doit être plus élevé (plus proche de 0) que le seuil de fin.'
            );
        }

        $settings['excluded_playlists'] = array_values(array_intersect(
            self::ids($input['excluded_playlists'] ?? []),
            $playlistIds
        ));

        if ($settings['enabled'] && $settings['activated_at'] === 0) {
            $settings['activated_at'] = $now;
        }

        return $settings;
    }

    /** @return int[] */
    private static function ids(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[(int)$id] = (int)$id;
            }
        }
        unset($ids[0]);
        sort($ids);

        return array_values($ids);
    }

    private static function number(float $value): string
    {
        return str_replace('.', ',', (string)$value);
    }
}
