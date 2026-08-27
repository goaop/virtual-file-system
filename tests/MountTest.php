<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

use Go\VirtualFileSystem\Exception\RegistrationException;
use Go\VirtualFileSystem\FileSystem;

final class MountTest extends VfsTestCase
{
    public function testMountRegistersStreamWrapper(): void
    {
        self::assertContains('vfs', stream_get_wrappers());
        self::assertTrue($this->fs->isMounted);
        self::assertSame('vfs', $this->fs->scheme);
        self::assertSame($this->fs, FileSystem::get('vfs'));
    }

    public function testUnmountUnregistersStreamWrapper(): void
    {
        $this->fs->unmount();

        self::assertNotContains('vfs', stream_get_wrappers());
        self::assertFalse($this->fs->isMounted);
        self::assertNull(FileSystem::get('vfs'));
    }

    public function testUnmountTwiceIsHarmless(): void
    {
        $this->fs->unmount();
        $this->fs->unmount();

        self::assertFalse($this->fs->isMounted);
    }

    public function testTreeSurvivesUnmount(): void
    {
        file_put_contents('vfs://kept.txt', 'still here');
        $this->fs->unmount();

        $node = $this->fs->find('/kept.txt');
        self::assertNotNull($node);
    }

    public function testMountingSameSchemeTwiceFails(): void
    {
        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('already mounted');

        FileSystem::mount('vfs');
    }

    public function testMountingOverNativeWrapperFails(): void
    {
        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('claimed by another stream wrapper');

        FileSystem::mount('php');
    }

    public function testInvalidSchemeIsRejected(): void
    {
        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('not a valid stream wrapper scheme');

        FileSystem::mount('no spaces');
    }

    public function testMultipleIndependentFileSystems(): void
    {
        $other = FileSystem::mount('vfs2');

        file_put_contents('vfs://only-here.txt', 'first');
        file_put_contents('vfs2://only-there.txt', 'second');

        self::assertTrue(file_exists('vfs://only-here.txt'));
        self::assertFalse(file_exists('vfs2://only-here.txt'));
        self::assertTrue(file_exists('vfs2://only-there.txt'));
        self::assertNotSame($this->fs->device, $other->device);

        $other->unmount();
        self::assertTrue($this->fs->isMounted);
    }

    public function testPathHelperBuildsUrls(): void
    {
        self::assertSame('vfs://', $this->fs->path());
        self::assertSame('vfs://a/b', $this->fs->path('/a/b'));
        self::assertSame('vfs://a/b', $this->fs->path('a//b/'));
    }
}
