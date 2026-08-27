<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem;

/**
 * Parsed fopen() mode string: "r", "w+", "ab", "xt", "c+e", …
 *
 * @internal
 */
final readonly class OpenMode
{
    private function __construct(
        public bool $readable,
        public bool $writable,
        public bool $append,
        public bool $truncate,
        public bool $exclusive,
        public bool $allowsCreation,
    ) {
    }

    /**
     * Parses an fopen() mode string, returning null for illegal modes.
     */
    public static function parse(string $mode): ?self
    {
        $flags = str_replace(['b', 't', 'e'], '', $mode);
        $base  = $flags === '' ? '' : $flags[0];
        if (!in_array($base, ['r', 'w', 'a', 'x', 'c'], true) || ltrim(substr($flags, 1), '+') !== '') {
            return null;
        }
        $readWrite = str_contains($flags, '+');

        return new self(
            readable: $readWrite || $base === 'r',
            writable: $readWrite || $base !== 'r',
            append: $base === 'a',
            truncate: $base === 'w',
            exclusive: $base === 'x',
            allowsCreation: $base !== 'r',
        );
    }
}
