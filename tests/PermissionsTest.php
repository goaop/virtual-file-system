<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

final class PermissionsTest extends VfsTestCase
{
    public function testUnreadableFileCannotBeOpened(): void
    {
        $this->fs->createFile('/secret.txt', 'classified', 0o200);
        $node = $this->fs->find('/secret.txt');
        self::assertNotNull($node);
        $node->chown(2000);
        $this->fs->user = 1000;

        [$handle, $warnings] = self::capture(static fn (): mixed => fopen('vfs://secret.txt', 'r'));

        self::assertFalse($handle);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Permission denied', $warnings[0]);
    }

    public function testUnwritableFileCannotBeOpenedForWriting(): void
    {
        $this->fs->createFile('/readonly.txt', 'look but do not touch', 0o444);
        $this->fs->user = 1000;

        [$handle, $warnings] = self::capture(static fn (): mixed => fopen('vfs://readonly.txt', 'w'));

        self::assertFalse($handle);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Permission denied', $warnings[0]);

        self::assertNotFalse(fopen('vfs://readonly.txt', 'r'));
    }

    public function testCannotCreateFileInReadOnlyDirectory(): void
    {
        $this->fs->createDirectory('/locked', 0o555);
        $this->fs->user = 1000;

        [$result, $warnings] = self::capture(static fn (): mixed => file_put_contents('vfs://locked/new.txt', 'x'));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Permission denied', implode(' ', $warnings));
    }

    public function testCannotDeleteFromReadOnlyDirectory(): void
    {
        $this->fs->createDirectory('/locked', 0o755);
        $this->fs->createFile('/locked/keep.txt', 'x');
        $node = $this->fs->find('/locked');
        self::assertNotNull($node);
        $node->chmod(0o555);
        $this->fs->user = 1000;

        [$result, $warnings] = self::capture(static fn (): bool => unlink('vfs://locked/keep.txt'));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Permission denied', $warnings[0]);
        self::assertTrue(file_exists('vfs://locked/keep.txt'));
    }

    public function testCannotMkdirInReadOnlyDirectory(): void
    {
        $this->fs->createDirectory('/locked', 0o555);
        $this->fs->user = 1000;

        [$result, $warnings] = self::capture(static fn (): bool => mkdir('vfs://locked/sub'));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Permission denied', $warnings[0]);
    }

    public function testUnreadableDirectoryCannotBeListed(): void
    {
        $this->fs->createDirectory('/private', 0o311);
        $this->fs->user = 1000;

        [$result, $warnings] = self::capture(static fn (): mixed => scandir('vfs://private'));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Permission denied', implode(' ', $warnings));
    }

    public function testRootBypassesPermissionChecks(): void
    {
        $this->fs->createFile('/secret.txt', 'classified', 0o000);
        $this->fs->user = 0;

        self::assertSame('classified', file_get_contents('vfs://secret.txt'));
    }

    public function testGroupPermissionsApply(): void
    {
        $this->fs->createFile('/group.txt', 'shared', 0o040);
        $node = $this->fs->find('/group.txt');
        self::assertNotNull($node);
        $node->chown(2000);
        $node->chgrp(3000);
        $this->fs->user = 1000;
        $this->fs->group = 3000;

        self::assertSame('shared', file_get_contents('vfs://group.txt'));

        $this->fs->group = 4000;
        [$handle, $warnings] = self::capture(static fn (): mixed => fopen('vfs://group.txt', 'r'));
        self::assertFalse($handle);
        self::assertNotEmpty($warnings);
    }

    public function testRenameRequiresWritableParents(): void
    {
        $this->fs->createDirectory('/locked', 0o755);
        $this->fs->createFile('/locked/file.txt', 'x');
        $node = $this->fs->find('/locked');
        self::assertNotNull($node);
        $node->chmod(0o555);
        $this->fs->user = 1000;

        [$result, $warnings] = self::capture(static fn (): bool => rename('vfs://locked/file.txt', 'vfs://file.txt'));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Permission denied', $warnings[0]);
    }
}
