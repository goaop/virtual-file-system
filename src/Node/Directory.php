<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Node;

/**
 * Directory node: an ordered map of child names to nodes.
 */
final class Directory extends Node
{
    public NodeType $type {
        get => NodeType::Directory;
    }

    public int $size {
        get => 4096;
    }

    /**
     * @var array<string, Node> Child nodes keyed by entry name
     */
    public private(set) array $children = [];

    public bool $isEmpty {
        get => $this->children === [];
    }

    /**
     * POSIX-style link count: "." and ".." plus one per subdirectory.
     */
    public int $linkCount {
        get => 2 + count(array_filter($this->children, static fn (Node $child): bool => $child instanceof self));
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
}
