<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Console;

use Plugin\AzuracastTraitementFichiers\Service\Processor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * docker compose exec web azuracast_cli traitement-fichiers:executer
 *
 * Runs one pass of the background processing right away and shows what it does.
 */
#[AsCommand(
    name: self::NAME,
    description: 'Traitement Fichiers : lance tout de suite un passage du traitement en arrière-plan.'
)]
final class RunCommand extends Command
{
    public const string NAME = 'traitement-fichiers:executer';

    public function __construct(
        private readonly Processor $processor
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stats = $this->processor->run(Processor::BUDGET_SECONDS, static function (string $message) use ($io): void {
            $io->writeln($message);
        });
        if ($stats['skipped'] === '') {
            $io->success(sprintf(
                '%d fichier(s) analysé(s), %d échec(s), %d en attente.',
                $stats['analysed'],
                $stats['failed'],
                $stats['waiting']
            ));
        }

        return 0;
    }
}
