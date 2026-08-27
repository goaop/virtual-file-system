<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Node;

/**
 * Symbolic link node pointing to another path.
 *
 * The target may be absolute ("/etc/config") or relative to the
 * directory containing the link ("../config").
 */
final class SymbolicLink extends Node
{
    public function __construct(
        private readonly string $target,
        int $permissions,
        int $uid,
        int $gid,
    ) {
        parent::__construct($permissions, $uid, $gid);
    }

    public function fileType(): int
    {
        return 0o120000;
    }

    public function size(): int
    {
        return strlen($this->target);
    }

    public function target(): string
    {
        return $this->target;
    }
}
