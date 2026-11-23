<?php

declare(strict_types=1);

// Minimal stand-ins for the AzuraCast classes used by the Processor, so that it can be tested
// without AzuraCast (real MariaDB, real FFmpeg, fake storage). Loaded only by tests/run.php.

namespace Psr\Log {
    interface LoggerInterface
    {
        public function info(string|\Stringable $message, array $context = []): void;

        public function warning(string|\Stringable $message, array $context = []): void;

        public function error(string|\Stringable $message, array $context = []): void;
    }
}

namespace App\MessageQueue {
    enum QueueNames: string
    {
        case SlowMedia = 'media';
    }

    interface QueueManagerInterface
    {
        public function getQueueCount(QueueNames $queue): int;
    }
}

namespace App\Flysystem {
    interface ExtendedFilesystemInterface
    {
        public function fileExists(string $path): bool;

        public function fileSize(string $path): int;

        /** @return resource */
        public function readStream(string $path);

        public function getLocalPath(string $path): string;
    }
}

namespace App\Entity {
    final class StationBackendConfiguration
    {
        public function __construct(
            public float $crossfade = 2.0,
            public string $crossfade_type = 'normal',
            public bool $enable_auto_cue = false
        ) {
        }

        public function isCrossfadeEnabled(): bool
        {
            return !$this->enable_auto_cue && $this->crossfade_type !== 'none' && $this->crossfade > 0;
        }
    }

    final class StorageLocationAdapterKind
    {
        public function __construct(private bool $local)
        {
        }

        public function isLocal(): bool
        {
            return $this->local;
        }
    }

    final class StorageLocation
    {
        public function __construct(
            public int $id,
            public StorageLocationAdapterKind $adapter
        ) {
        }
    }

    final class Station
    {
        public function __construct(
            public int $id,
            public string $name,
            public StorageLocation $media_storage_location,
            public StationBackendConfiguration $backend_config,
            private string $configDir
        ) {
        }

        public function getRadioConfigDir(): string
        {
            return $this->configDir;
        }
    }
}

namespace App\Entity\Repository {
    use App\Entity\StorageLocation;
    use App\Flysystem\ExtendedFilesystemInterface;

    final class StorageLocationRepository
    {
        /** @param array<int, ExtendedFilesystemInterface> $filesystems */
        public function __construct(public array $filesystems)
        {
        }

        public function getAdapter(StorageLocation $location): object
        {
            $fs = $this->filesystems[$location->id];

            return new class($fs) {
                public function __construct(private ExtendedFilesystemInterface $fs)
                {
                }

                public function getFilesystem(): ExtendedFilesystemInterface
                {
                    return $this->fs;
                }
            };
        }
    }
}

namespace App\Doctrine {
    interface ReloadableEntityManagerInterface
    {
        public function find(string $class, mixed $id): ?object;
    }
}

namespace App\Cache {
    final class MediaListCache
    {
        public int $cleared = 0;

        public function clearCache(mixed $tag): void
        {
            $this->cleared++;
        }
    }
}

namespace App {
    final class Environment
    {
        public function __construct(private string $temp)
        {
        }

        public function getTempDirectory(): string
        {
            return $this->temp;
        }
    }
}
