<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Node;

/**
 * Directory node: an ordered map of child names to nodes.
 */
final class Directory extends Node
{
    /**
     * @var array<string, Node>
     */
    private array $children = [];

    public function fileType(): int
    {
        return 0o040000;
    }

    public function size(): int
    {
        return 4096;
    }

    public function child(string $name): ?Node
    {
        return $this->children[$name] ?? null;
    }

    public function hasChild(string $name): bool
    {
        return isset($this->children[$name]);
    }

    public function addChild(string $name, Node $node): void
    {
        $this->children[$name] = $node;
        $this->markModified();
    }

    public function removeChild(string $name): void
    {
        unset($this->children[$name]);
        $this->markModified();
    }

    /**
     * @return array<string, Node>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function isEmpty(): bool
    {
        return $this->children === [];
    }

    /**
     * POSIX-style link count: "." and ".." plus one per subdirectory.
     */
    public function linkCount(): int
    {
        $count = 2;
        foreach ($this->children as $child) {
            if ($child instanceof self) {
                $count++;
            }
        }

        return $count;
    }
}
