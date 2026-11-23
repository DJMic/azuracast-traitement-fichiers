<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Service;

/**
 * The few database operations the plugin needs (positional "?" parameters, scalars only).
 * In AzuraCast: DbalSql (Doctrine connection). In the tests: a PDO connection.
 */
interface Sql
{
    /** @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array;

    /** @return int Number of affected rows. */
    public function execute(string $sql, array $params = []): int;

    /** Reconnects if the connection was dropped (long download, MariaDB timeout…). */
    public function ensureConnected(): void;
}
