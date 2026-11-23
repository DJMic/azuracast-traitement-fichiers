<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Service;

use App\Doctrine\ReloadableEntityManagerInterface;
use Throwable;

/** Sql on AzuraCast's own Doctrine connection. */
final class DbalSql implements Sql
{
    public function __construct(
        private readonly ReloadableEntityManagerInterface $em
    ) {
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return array_values($this->em->getConnection()->fetchAllAssociative($sql, array_values($params)));
    }

    public function execute(string $sql, array $params = []): int
    {
        return (int)$this->em->getConnection()->executeStatement($sql, array_values($params));
    }

    public function ensureConnected(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            return;
        }
        try {
            $connection->executeQuery('SELECT 1');
        } catch (Throwable) {
            $connection->close(); // the next query opens a fresh connection
        }
    }
}
