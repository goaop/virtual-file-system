<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Node;

/**
 * Base class for every inode of the virtual filesystem.
 *
 * Tracks POSIX-like metadata: a unique inode number, permission bits,
 * owner/group ids and access/modification/change timestamps.
 */
abstract class Node
{
    private static int $nextInode = 1;

    public readonly int $inode;

    private int $permissions;

    private int $uid;

    private int $gid;

    private int $accessTime;

    private int $modificationTime;

    private int $changeTime;

    public function __construct(int $permissions, int $uid, int $gid)
    {
        $this->inode       = self::$nextInode++;
        $this->permissions = $permissions & 0o7777;
        $this->uid         = $uid;
        $this->gid         = $gid;

        $now                    = time();
        $this->accessTime       = $now;
        $this->modificationTime = $now;
        $this->changeTime       = $now;
    }

    /**
     * File type bits for the st_mode field (S_IFREG, S_IFDIR or S_IFLNK).
     */
    abstract public function fileType(): int;

    /**
     * Apparent size of the node in bytes.
     */
    abstract public function size(): int;

    /**
     * Full st_mode value: file type bits combined with permission bits.
     */
    final public function mode(): int
    {
        return $this->fileType() | $this->permissions;
    }

    final public function permissions(): int
    {
        return $this->permissions;
    }

    final public function chmod(int $permissions): void
    {
        $this->permissions = $permissions & 0o7777;
        $this->changeTime  = time();
    }

    final public function uid(): int
    {
        return $this->uid;
    }

    final public function chown(int $uid): void
    {
        $this->uid       = $uid;
        $this->changeTime = time();
    }

    final public function gid(): int
    {
        return $this->gid;
    }

    final public function chgrp(int $gid): void
    {
        $this->gid        = $gid;
        $this->changeTime = time();
    }

    final public function accessTime(): int
    {
        return $this->accessTime;
    }

    final public function modificationTime(): int
    {
        return $this->modificationTime;
    }

    final public function changeTime(): int
    {
        return $this->changeTime;
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
        if ($uid === 0) {
            return true;
        }
        if ($uid === $this->uid) {
            return ($this->permissions & $ownerBit) !== 0;
        }
        if ($gid === $this->gid) {
            return ($this->permissions & $groupBit) !== 0;
        }

        return ($this->permissions & $otherBit) !== 0;
    }
}
