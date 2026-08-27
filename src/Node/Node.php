<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Node;

/**
 * A single inode of the virtual filesystem.
 *
 * All POSIX metadata is exposed as first-class, engine-enforced property
 * declarations (PHP 8.4 interface property hooks); mutations go through
 * the intention-revealing methods which keep the change time honest.
 */
interface Node
{
    /**
     * Unique inode number of this node.
     */
    public int $inode { get; }

    /**
     * POSIX file type of this node (S_IFREG, S_IFDIR or S_IFLNK).
     */
    public NodeType $type { get; }

    /**
     * Apparent size of the node in bytes.
     */
    public int $size { get; }

    /**
     * Full st_mode value: file type bits combined with permission bits.
     */
    public int $mode { get; }

    /**
     * Permission bits within the 0o7777 range.
     */
    public int $permissions { get; }

    public int $uid { get; }

    public int $gid { get; }

    public int $accessTime { get; }

    public int $modificationTime { get; }

    public int $changeTime { get; }

    public function chmod(int $permissions): void;

    public function chown(int $uid): void;

    public function chgrp(int $gid): void;

    /**
     * Implements touch(): updates times, creating-file semantics live in the wrapper.
     */
    public function touch(?int $modificationTime = null, ?int $accessTime = null): void;

    public function markAccessed(): void;

    public function markModified(): void;

    public function isReadableBy(int $uid, int $gid): bool;

    public function isWritableBy(int $uid, int $gid): bool;

    public function isExecutableBy(int $uid, int $gid): bool;
}
