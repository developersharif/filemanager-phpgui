<?php

declare(strict_types=1);

namespace FileManager;

final class Clipboard
{
    public const MODE_NONE = 0;
    public const MODE_COPY = 1;
    public const MODE_CUT  = 2;

    /** @var list<string> */
    private array $paths = [];
    private int $mode = self::MODE_NONE;

    /** @param list<string> $paths */
    public function copy(array $paths): void { $this->paths = $paths; $this->mode = self::MODE_COPY; }
    /** @param list<string> $paths */
    public function cut(array $paths): void  { $this->paths = $paths; $this->mode = self::MODE_CUT;  }

    public function clear(): void { $this->paths = []; $this->mode = self::MODE_NONE; }

    public function hasContent(): bool { return $this->paths !== []; }
    public function isCut(): bool { return $this->mode === self::MODE_CUT; }
    /** @return list<string> */
    public function paths(): array { return $this->paths; }
}
