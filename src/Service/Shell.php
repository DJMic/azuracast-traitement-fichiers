<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Service;

use RuntimeException;

/**
 * Runs a program without a shell (arguments are never interpreted) with a time limit.
 */
final class Shell
{
    /**
     * @param string[] $command
     *
     * @return array{code: int, out: string, err: string}
     */
    public static function run(array $command, int $timeout = 120): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Impossible de lancer ' . $command[0] . '.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                foreach ($read as $pipe) {
                    $chunk = (string)fread($pipe, 65536);
                    if ($pipe === $pipes[1]) {
                        $out .= $chunk;
                    } else {
                        $err = substr($err . $chunk, -20000);
                    }
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $out .= (string)stream_get_contents($pipes[1]);
                $err = substr($err . (string)stream_get_contents($pipes[2]), -20000);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ['code' => (int)$status['exitcode'], 'out' => $out, 'err' => $err];
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException($command[0] . ' a dépassé le délai de ' . $timeout . ' secondes.');
            }
        }
    }

    public static function which(string $program): ?string
    {
        foreach (explode(':', (string)getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $program)) {
                return $dir . '/' . $program;
            }
        }
        foreach (['/usr/local/bin', '/usr/bin', '/bin'] as $dir) {
            if (is_executable($dir . '/' . $program)) {
                return $dir . '/' . $program;
            }
        }

        return null;
    }
}
