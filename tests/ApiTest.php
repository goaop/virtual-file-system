<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

use Go\VirtualFileSystem\Exception\OperationException;
use Go\VirtualFileSystem\Node\Directory;
use Go\VirtualFileSystem\Node\File;
use Go\VirtualFileSystem\Node\SymbolicLink;

final class ApiTest extends VfsTestCase
{
    public function testCreateFileBuildsParentsAutomatically(): void
    {
        $file = $this->fs->createFile('/deep/nested/file.txt', 'seeded');

        self::assertInstanceOf(File::class, $file);
        self::assertSame('seeded', file_get_contents('vfs://deep/nested/file.txt'));
        self::assertTrue(is_dir('vfs://deep/nested'));
    }

    public function testCreateFileOverDirectoryFails(): void
    {
        $this->fs->createDirectory('/dir');

        $this->expectException(OperationException::class);
        $this->fs->createFile('/dir', 'x');
    }

    public function testCreateDirectoryNonRecursiveRequiresParent(): void
    {
        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('does not exist');

        $this->fs->createDirectory('/missing/child');
    }

    public function testCreateDirectoryIsIdempotentForExistingDirectory(): void
    {
        $first  = $this->fs->createDirectory('/twice');
        $second = $this->fs->createDirectory('/twice');

        self::assertSame($first, $second);
    }

    public function testCreateSymlinkRefusesOccupiedPath(): void
    {
        $this->fs->createFile('/busy.txt', 'x');

        $this->expectException(OperationException::class);
        $this->fs->createSymlink('/busy.txt', '/anywhere');
    }

    public function testFindReturnsTypedNodes(): void
    {
        $this->fs->createDirectory('/d');
        $this->fs->createFile('/d/f.txt', 'x');
        $this->fs->createSymlink('/d/l', '/d/f.txt');

        self::assertInstanceOf(Directory::class, $this->fs->find('/d'));
        self::assertInstanceOf(File::class, $this->fs->find('/d/f.txt'));
        self::assertInstanceOf(SymbolicLink::class, $this->fs->find('/d/l', followFinalLink: false));
        self::assertInstanceOf(File::class, $this->fs->find('/d/l'));
        self::assertNull($this->fs->find('/nope'));
    }

    public function testRootIsAlwaysAvailable(): void
    {
        self::assertInstanceOf(Directory::class, $this->fs->root());
        self::assertSame($this->fs->root(), $this->fs->find('/'));
        self::assertSame($this->fs->root(), $this->fs->createDirectory('/'));
    }

    public function testDefaultOwnershipComesFromProcess(): void
    {
        $expectedUid = function_exists('posix_getuid') ? posix_getuid() : 0;

        self::assertSame($expectedUid, $this->fs->user());

        $file = $this->fs->createFile('/owned.txt');
        self::assertSame($expectedUid, $file->uid());
    }

    public function testNodeContentAccessors(): void
    {
        $file = $this->fs->createFile('/direct.txt', 'initial');
        $file->setContent('replaced');

        self::assertSame('replaced', file_get_contents('vfs://direct.txt'));
        self::assertSame('replaced', $file->content());
        self::assertSame(8, $file->size());
    }
}
