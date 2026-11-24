<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Controller;

use App\Entity\Station;
use App\Http\Response;
use App\Http\ServerRequest;
use InvalidArgumentException;
use Plugin\AzuracastTraitementFichiers\Service\Plugin;
use Plugin\AzuracastTraitementFichiers\Service\Processor;
use Plugin\AzuracastTraitementFichiers\Service\Settings;
use Plugin\AzuracastTraitementFichiers\Service\Store;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** JSON API of the "Traitement Fichiers" tab (station settings page). */
final class PanelController
{
    public const string CSRF_NAMESPACE = 'traitement_fichiers';

    public function __construct(
        private readonly Store $store,
        private readonly Processor $processor
    ) {
    }

    public function assetAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $path = Plugin::dir() . '/frontend/tab.js';
        if (!is_file($path)) {
            return $response->withStatus(404);
        }
        $response->getBody()->write((string)file_get_contents($path));

        return $response
            ->withHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function stateAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $station = $request->getStation();

            return $this->json($response, [
                'version' => Plugin::version(),
                'csrf' => $request->getCsrf()->generate(self::CSRF_NAMESPACE),
                ...$this->state($station),
            ]);
        });
    }

    public function saveAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            $station = $request->getStation();
            $configDir = $station->getRadioConfigDir();
            $settings = Settings::fromInput($this->body($request), $this->store->readSettings($configDir), time());
            $this->store->writeSettings($configDir, $settings);

            return $this->json($response, [
                'success' => true,
                'message' => 'Réglages enregistrés.',
                ...$this->state($station),
            ]);
        });
    }

    /** Starts or stops the analysis of the files that were already there before the activation. */
    public function backfillAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            $station = $request->getStation();
            $start = filter_var($this->body($request)['start'] ?? false, FILTER_VALIDATE_BOOL);
            if ($start && !$this->store->readSettings($station->getRadioConfigDir())['enabled']) {
                throw new InvalidArgumentException('Activez d\'abord le traitement des fichiers (et enregistrez).');
            }
            $this->store->updateState($station->getRadioConfigDir(), static function (array $state) use ($start): array {
                $state['backfill'] = $start;

                return $state;
            });

            return $this->json($response, [
                'success' => true,
                'message' => $start
                    ? 'Analyse des fichiers existants lancée : elle avance chaque minute, en arrière-plan.'
                    : 'Analyse des fichiers existants arrêtée.',
                ...$this->state($station),
            ]);
        });
    }

    private function state(Station $station): array
    {
        $configDir = $station->getRadioConfigDir();
        $settings = $this->store->readSettings($configDir);
        $state = $this->store->readState($configDir);
        $locationId = (int)$station->media_storage_location->id;
        $blocked = Processor::blockedIds($state, time());
        $finder = $this->processor->finder();
        $folders = $finder->folders($locationId);
        $known = [];
        foreach ($folders as $folder) {
            $known[mb_strtolower($folder['path'])] = true;
        }

        $owner = $this->processor->settingsOwner($station);

        return [
            'station' => ['id' => $station->id, 'name' => $station->name],
            'settings' => $settings,
            'limits' => Settings::LIMITS,
            'target_lufs' => Settings::TARGET_LUFS,
            'max_duration' => Settings::MAX_DURATION,
            'max_fade_out' => Processor::maxOverlap($station),
            'crossfade_enabled' => Processor::hasCrossfade($station),
            'autocue_enabled' => (bool)$station->backend_config->enable_auto_cue,
            'folders' => $folders,
            // Excluded folders that no longer exist (renamed or deleted): they exclude nothing now.
            'missing_folders' => array_values(array_filter(
                $settings['excluded_folders'],
                static fn(string $folder): bool => !isset($known[mb_strtolower($folder)])
            )),
            // Another station sharing the same media decides (lowest ID): this one's settings are not used.
            'owner' => $owner !== null && $owner->id !== $station->id
                ? ['id' => $owner->id, 'name' => $owner->name]
                : null,
            'backfill' => $state['backfill'],
            'pending' => $settings['enabled'] && $settings['activated_at'] > 0
                ? $finder->count($locationId, $settings, false, $blocked)
                : 0,
            'existing' => $finder->count($locationId, $settings, true, $blocked),
            'failed' => count($blocked),
        ];
    }

    private function verifyCsrf(ServerRequest $request): void
    {
        try {
            $request->getCsrf()->verify($request->getHeaderLine('X-CSRF-Token'), self::CSRF_NAMESPACE);
        } catch (Throwable) {
            throw new InvalidArgumentException('Session expirée : rechargez la page puis recommencez.');
        }
    }

    private function body(ServerRequest $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = json_decode((string)$request->getBody(), true);
        }

        return is_array($body) ? $body : [];
    }

    private function json(Response $response, array $data, int $status = 200): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store')->withJson($data, $status);
    }

    /** @param callable(): ResponseInterface $action */
    private function guard(Response $response, callable $action): ResponseInterface
    {
        try {
            return $action();
        } catch (InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
