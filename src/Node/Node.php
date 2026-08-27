<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Node;

/**
 * Base class for every inode of the virtual filesystem.
 *
 * Tracks POSIX-like metadata: a unique inode number, permission bits,
 * owner/group ids and access/modification/change timestamps. Metadata is
 * exposed as read-only properties (asymmetric visibility); mutations go
 * through the intention-revealing methods — chmod(), chown(), touch() —
 * which keep the change time honest.
 */
abstract class Node
{
    private static int $nextInode = 1;

    public readonly int $inode;

    /**
     * POSIX file type of this node (S_IFREG, S_IFDIR or S_IFLNK).
     */
    abstract public NodeType $type { get; }

    /**
     * Apparent size of the node in bytes.
     */
    abstract public int $size { get; }

    /**
     * Full st_mode value: file type bits combined with permission bits.
     */
    public int $mode {
        get => $this->type->value | $this->permissions;
    }

    /**
     * Permission bits, always masked to the valid 0o7777 range.
     */
    public protected(set) int $permissions {
        set => $value & 0o7777;
    }

    public private(set) int $uid;

    public private(set) int $gid;

    public private(set) int $accessTime;

    public private(set) int $modificationTime;

    public private(set) int $changeTime;

    public function __construct(int $permissions, int $uid, int $gid)
    {
        $this->inode       = self::$nextInode++;
        $this->permissions = $permissions;
        $this->uid         = $uid;
        $this->gid         = $gid;

        $now                    = time();
        $this->accessTime       = $now;
        $this->modificationTime = $now;
        $this->changeTime       = $now;
    }

    final public function chmod(int $permissions): void
    {
        $this->permissions = $permissions;
        $this->changeTime  = time();
    }

    final public function chown(int $uid): void
    {
        $this->uid        = $uid;
        $this->changeTime = time();
    }

    final public function chgrp(int $gid): void
    {
        $this->gid        = $gid;
        $this->changeTime = time();
    }

    /**
     * Implements touch(): updates times, creating-file semantics live in the wrapper.
     */
    final public function touch(?int $modificationTime = null, ?int $accessTime = null): void
    {
        $now                    = time();
        $this->modificationTime = $modificationTime ?? $now;
        $this->accessTime       = $accessTime ?? $this->modificationTime;
        $this->changeTime       = $now;
    }

    final public function markAccessed(): void
    {
        $this->accessTime = time();
    }

    final public function markModified(): void
    {
        $now                    = time();
        $this->modificationTime = $now;
        $this->changeTime       = $now;
    }

    final public function isReadableBy(int $uid, int $gid): bool
    {
        return $this->hasPermissionBit($uid, $gid, 0o400, 0o040, 0o004);
    }

    final public function isWritableBy(int $uid, int $gid): bool
    {
        return $this->hasPermissionBit($uid, $gid, 0o200, 0o020, 0o002);
    }

    final public function isExecutableBy(int $uid, int $gid): bool
    {
        return $this->hasPermissionBit($uid, $gid, 0o100, 0o010, 0o001);
    }

    private function hasPermissionBit(int $uid, int $gid, int $ownerBit, int $groupBit, int $otherBit): bool
    {
        return match (true) {
            $uid === 0          => true,
            $uid === $this->uid => ($this->permissions & $ownerBit) !== 0,
            $gid === $this->gid => ($this->permissions & $groupBit) !== 0,
            default             => ($this->permissions & $otherBit) !== 0,
        };
    }
}
