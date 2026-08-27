<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

use Go\VirtualFileSystem\FileSystem;
use PHPUnit\Framework\TestCase;

abstract class VfsTestCase extends TestCase
{
    protected FileSystem $fs;

    #[\Override]
    protected function setUp(): void
    {
        $this->fs = FileSystem::mount('vfs');
    }

    #[\Override]
    protected function tearDown(): void
    {
        FileSystem::unmountAll();
    }

    /**
     * Runs an operation while collecting the PHP warnings/notices it raises,
     * so expected failure diagnostics never leak into PHPUnit's error handler.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return array{T, list<string>} The operation result and raised messages
     */
    protected static function capture(callable $operation): array
    {
        $messages = [];
        set_error_handler(static function (int $severity, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        });
        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return [$result, $messages];
    }

    /**
     * Opens a stream handle, failing the test when fopen() does not succeed.
     *
     * @return resource
     */
    protected static function open(string $url, string $mode)
    {
        $handle = fopen($url, $mode);
        self::assertIsResource($handle, sprintf('fopen(%s, %s) should succeed', $url, $mode));
        assert(is_resource($handle));

        return $handle;
    }

    /**
     * Returns the stat array of a URL, failing the test when stat() fails.
     *
     * @return array<int|string, int>
     */
    protected static function statOf(string $url): array
    {
        $stat = stat($url);
        self::assertIsArray($stat, sprintf('stat(%s) should succeed', $url));

        return $stat;
    }
}
