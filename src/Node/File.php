<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Node;

/**
 * Regular file: binary-safe content plus advisory locking state.
 *
 * The content lives on the node itself, so every open handle observes
 * writes made through any other handle — the same guarantee a real
 * filesystem gives for a shared inode. The set hook on $content keeps
 * the modification time up to date on every write, wherever it comes from.
 */
final class File extends AbstractNode
{
    public NodeType $type {
        get => NodeType::File;
    }

    /**
     * Raw file content; assigning marks the file as modified.
     */
    public string $content = '' {
        set {
            $this->content = $value;
            $this->markModified();
        }
    }

    public int $size {
        get => strlen($this->content);
    }

    private ?int $exclusiveLockOwner = null;

    /**
     * @var array<int, true> Handle ids currently holding a shared lock
     */
    private array $sharedLockOwners = [];

    /**
     * Reads up to $count bytes starting at $offset.
     */
    public function read(int $offset, int $count): string
    {
        $this->markAccessed();
        if ($offset >= strlen($this->content) || $count <= 0) {
            return '';
        }

        return substr($this->content, $offset, $count);
    }

    /**
     * Writes $data at $offset, zero-padding any gap beyond the current end.
     *
     * @return int Number of bytes written
     */
    public function write(int $offset, string $data): int
    {
        $content = $this->content;
        if ($offset > strlen($content)) {
            $content .= str_repeat("\0", $offset - strlen($content));
        }
        $this->content = substr_replace($content, $data, $offset, strlen($data));

        return strlen($data);
    }

    /**
     * Grows (zero-padded) or shrinks the file to exactly $size bytes.
     */
    public function truncate(int $size): void
    {
        $currentSize = strlen($this->content);
        $this->content = match (true) {
            $size < $currentSize => substr($this->content, 0, $size),
            $size > $currentSize => $this->content . str_repeat("\0", $size - $currentSize),
            default              => $this->content,
        };
    }

    /**
     * Acquires a shared (read) lock for the given handle id.
     */
    public function lockShared(int $ownerId): bool
    {
        if ($this->exclusiveLockOwner !== null && $this->exclusiveLockOwner !== $ownerId) {
            return false;
        }
        if ($this->exclusiveLockOwner === $ownerId) {
            $this->exclusiveLockOwner = null;
        }
        $this->sharedLockOwners[$ownerId] = true;

        return true;
    }

    /**
     * Acquires an exclusive (write) lock for the given handle id.
     */
    public function lockExclusive(int $ownerId): bool
    {
        $lockedByOthers = ($this->exclusiveLockOwner !== null && $this->exclusiveLockOwner !== $ownerId)
            || array_any(
                array_keys($this->sharedLockOwners),
                static fn (int $sharedOwnerId): bool => $sharedOwnerId !== $ownerId,
            );
        if ($lockedByOthers) {
            return false;
        }
        unset($this->sharedLockOwners[$ownerId]);
        $this->exclusiveLockOwner = $ownerId;

        return true;
    }

    /**
     * Releases any lock held by the given handle id.
     */
    public function unlock(int $ownerId): void
    {
        if ($this->exclusiveLockOwner === $ownerId) {
            $this->exclusiveLockOwner = null;
        }
        unset($this->sharedLockOwners[$ownerId]);
    }
}
