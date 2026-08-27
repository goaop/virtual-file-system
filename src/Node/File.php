<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Node;

/**
 * Regular file: binary-safe content plus advisory locking state.
 *
 * The content lives on the node itself, so every open handle observes
 * writes made through any other handle — the same guarantee a real
 * filesystem gives for a shared inode.
 */
final class File extends Node
{
    private string $content = '';

    private ?int $exclusiveLockOwner = null;

    /**
     * @var array<int, true> Handle ids currently holding a shared lock
     */
    private array $sharedLockOwners = [];

    public function fileType(): int
    {
        return 0o100000;
    }

    public function size(): int
    {
        return strlen($this->content);
    }

    public function content(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
        $this->markModified();
    }

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
        $currentSize = strlen($this->content);
        if ($offset > $currentSize) {
            $this->content .= str_repeat("\0", $offset - $currentSize);
        }
        $this->content = substr_replace($this->content, $data, $offset, strlen($data));
        $this->markModified();

        return strlen($data);
    }

    /**
     * Grows (zero-padded) or shrinks the file to exactly $size bytes.
     */
    public function truncate(int $size): void
    {
        $currentSize = strlen($this->content);
        if ($size < $currentSize) {
            $this->content = substr($this->content, 0, $size);
        } elseif ($size > $currentSize) {
            $this->content .= str_repeat("\0", $size - $currentSize);
        }
        $this->markModified();
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
        if ($this->exclusiveLockOwner !== null && $this->exclusiveLockOwner !== $ownerId) {
            return false;
        }
        $otherSharedOwners = $this->sharedLockOwners;
        unset($otherSharedOwners[$ownerId]);
        if ($otherSharedOwners !== []) {
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
