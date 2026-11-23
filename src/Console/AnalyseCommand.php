<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Console;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use App\Entity\StationMedia;
use Plugin\AzuracastTraitementFichiers\Service\Analyzer;
use Plugin\AzuracastTraitementFichiers\Service\MediaFinder;
use Plugin\AzuracastTraitementFichiers\Service\Processor;
use Plugin\AzuracastTraitementFichiers\Service\Settings;
use Plugin\AzuracastTraitementFichiers\Service\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * docker compose exec web azuracast_cli traitement-fichiers:analyser <station> <média>
 *
 * Analyses one file with the station's settings and shows the values, without saving anything
 * (unless --enregistrer, and only if the file's values are all empty). <média> is the ID of the
 * file or its path in the station's media.
 */
#[AsCommand(
    name: self::NAME,
    description: 'Traitement Fichiers : analyse un fichier et affiche ses points de cue (essai, sans enregistrer).'
)]
final class AnalyseCommand extends Command
{
    use EntityManagerAwareTrait;

    public const string NAME = 'traitement-fichiers:analyser';

    public function __construct(
        private readonly Analyzer $analyzer,
        private readonly Store $store,
        private readonly Processor $processor
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('station', InputArgument::REQUIRED, 'ID ou nom court de la station')
            ->addArgument('media', InputArgument::REQUIRED, 'ID du fichier, ou son chemin dans les médias de la station')
            ->addOption('enregistrer', null, InputOption::VALUE_NONE, 'Enregistrer les valeurs si celles du fichier sont toutes vides');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = (string)$input->getArgument('station');
        $repo = $this->em->getRepository(Station::class);
        $station = ctype_digit($key) ? $repo->find((int)$key) : $repo->findOneBy(['short_name' => $key]);
        if (!$station instanceof Station) {
            $io->error('Station introuvable : ' . $key);

            return 1;
        }

        $storageLocation = $station->media_storage_location;
        $mediaKey = (string)$input->getArgument('media');
        $mediaRepo = $this->em->getRepository(StationMedia::class);
        $media = ctype_digit($mediaKey)
            ? $mediaRepo->findOneBy(['id' => (int)$mediaKey, 'storage_location' => $storageLocation])
            : $mediaRepo->findOneBy(['path' => $mediaKey, 'storage_location' => $storageLocation]);
        if (!$media instanceof StationMedia) {
            $io->error('Fichier introuvable dans les médias de cette station : ' . $mediaKey);

            return 1;
        }

        $owner = $this->processor->settingsOwner($station) ?? $station;
        $settings = $this->store->readSettings($owner->getRadioConfigDir());
        $maxOverlap = Processor::maxOverlap($owner);
        $io->writeln(sprintf(
            'Seuils : début %s dB, fin %s dB, fondu %s dB · fondu de sortie maximal %s s · cible %s LUFS',
            $settings['cue_in_threshold'],
            $settings['cue_out_threshold'],
            $settings['cross_threshold'],
            $maxOverlap,
            Settings::TARGET_LUFS
        ));
        if ($media->length > Settings::MAX_DURATION) {
            $io->note('Ce fichier dure plus de 8 minutes : le traitement automatique l\'ignore.');
        }

        try {
            $result = $this->processor->withFile(
                $storageLocation,
                $media->path,
                fn(string $path): array => $this->analyzer->analyse($path, $settings, $maxOverlap, $media->length)
            );
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $io->writeln(sprintf('Sonie intégrée : %.1f LUFS%s', $result['lufs'], $result['cross_found'] ? '' : ' (aucun point de fondu trouvé)'));
        $io->table(['Champ', 'Valeur'], array_map(
            static fn(string $field, float $value): array => [$field, $value],
            array_keys($result['values']),
            array_values($result['values'])
        ));

        $current = $media->extra_metadata->toArray() ?? [];
        $empty = MediaFinder::isEmpty($current);
        if (!$empty) {
            $io->writeln('Valeurs actuelles dans AzuraCast : ' . json_encode(array_filter($current, static fn($v) => $v !== null)));
        }

        if (!$input->getOption('enregistrer')) {
            $io->note('Essai : rien n\'a été enregistré (option --enregistrer pour enregistrer).');

            return 0;
        }
        if (!$empty) {
            $io->warning('Le fichier a déjà des valeurs : elles ne sont pas remplacées.');

            return 1;
        }
        if ($this->processor->finder()->write((int)$media->id, (int)$media->mtime, $result['values'])) {
            $io->success('Valeurs enregistrées.');

            return 0;
        }
        $io->warning('Rien n\'a été enregistré : le fichier a été modifié entre-temps.');

        return 1;
    }
}
