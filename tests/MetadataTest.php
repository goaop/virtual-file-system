<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

final class MetadataTest extends VfsTestCase
{
    public function testChmodChangesPermissions(): void
    {
        file_put_contents('vfs://file.txt', 'x');

        self::assertTrue(chmod('vfs://file.txt', 0o600));
        clearstatcache();

        self::assertSame(0o600, self::statOf('vfs://file.txt')['mode'] & 0o777);
    }

    public function testChmodOnMissingFileFails(): void
    {
        [$result, $warnings] = self::capture(static fn (): bool => chmod('vfs://missing', 0o600));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('No such file or directory', $warnings[0]);
    }

    public function testChownAsRootChangesOwner(): void
    {
        $this->fs->setUser(0);
        file_put_contents('vfs://owned.txt', 'x');

        self::assertTrue(chown('vfs://owned.txt', 1234));
        clearstatcache();

        self::assertSame(1234, fileowner('vfs://owned.txt'));
    }

    public function testChgrpAsRootChangesGroup(): void
    {
        $this->fs->setUser(0);
        file_put_contents('vfs://grouped.txt', 'x');

        self::assertTrue(chgrp('vfs://grouped.txt', 5678));
        clearstatcache();

        self::assertSame(5678, filegroup('vfs://grouped.txt'));
    }

    public function testChownAsRegularUserIsDenied(): void
    {
        file_put_contents('vfs://protected.txt', 'x');
        $this->fs->setUser(1000);

        [$result, $warnings] = self::capture(static fn (): bool => chown('vfs://protected.txt', 1234));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Operation not permitted', $warnings[0]);
    }

    public function testChgrpAsRegularUserIsDenied(): void
    {
        file_put_contents('vfs://protected.txt', 'x');
        $this->fs->setUser(1000);

        [$result, $warnings] = self::capture(static fn (): bool => chgrp('vfs://protected.txt', 42));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Operation not permitted', $warnings[0]);
    }

    public function testChmodByNonOwnerIsDenied(): void
    {
        file_put_contents('vfs://foreign.txt', 'x');
        $node = $this->fs->find('/foreign.txt');
        self::assertNotNull($node);
        $node->chown(2000);
        $this->fs->setUser(1000);

        [$result, $warnings] = self::capture(static fn (): bool => chmod('vfs://foreign.txt', 0o777));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Operation not permitted', $warnings[0]);
    }

    public function testChmodByOwnerIsAllowed(): void
    {
        file_put_contents('vfs://mine.txt', 'x');
        $node = $this->fs->find('/mine.txt');
        self::assertNotNull($node);
        $node->chown(1000);
        $this->fs->setUser(1000);

        self::assertTrue(chmod('vfs://mine.txt', 0o640));
        clearstatcache();

        self::assertSame(0o640, self::statOf('vfs://mine.txt')['mode'] & 0o777);
    }

    public function testChownByUserName(): void
    {
        if (!function_exists('posix_getpwnam') || posix_getpwnam('root') === false) {
            self::markTestSkipped('POSIX extension with a "root" user is required');
        }
        $this->fs->setUser(0);
        file_put_contents('vfs://byname.txt', 'x');

        self::assertTrue(chown('vfs://byname.txt', 'root'));
        clearstatcache();

        self::assertSame(0, fileowner('vfs://byname.txt'));
    }
}
