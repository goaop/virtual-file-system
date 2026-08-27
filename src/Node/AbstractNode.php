<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Node;

/**
 * Shared metadata state for every inode implementation.
 *
 * Concrete node types only provide their $type and $size hooks; everything
 * else — permissions, ownership, timestamps and the permission-bit checks —
 * lives here, guarded by asymmetric visibility so external writes are
 * impossible while reads stay plain property access.
 */
abstract class AbstractNode implements Node
{
    private static int $nextInode = 1;

    public readonly int $inode;

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
