<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Node;

/**
 * Symbolic link node pointing to another path.
 *
 * The target may be absolute ("/etc/config") or relative to the
 * directory containing the link ("../config").
 */
final class SymbolicLink extends AbstractNode
{
    public NodeType $type {
        get => NodeType::SymbolicLink;
    }

    public int $size {
        get => strlen($this->target);
    }

    public function __construct(
        public readonly string $target,
        int $permissions,
        int $uid,
        int $gid,
    ) {
        parent::__construct($permissions, $uid, $gid);
    }
}
