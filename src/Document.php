<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * Who a graph is. `version` is the application's own, free text: two
 * documents with the same name and different versions are two graphs.
 */
final readonly class Document
{
    public function __construct(
        public string $name,
        public string $version = '0',
        public string $namespace = 'default',
    ) {}

    /**
     * WHAT A SUBJECT IS PINNED TO — `namespace/name@version`. The whole
     * document names itself, not the version alone: two workflows that
     * happened to share a version string would otherwise claim each other's
     * subjects. Opaque to the drivers, which only ever compare it.
     */
    public function identity(): string
    {
        return "{$this->namespace}/{$this->name}@{$this->version}";
    }
}
