<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

final class StatTest extends VfsTestCase
{
    public function testFileExists(): void
    {
        file_put_contents('vfs://present.txt', 'x');

        self::assertTrue(file_exists('vfs://present.txt'));
        self::assertFalse(file_exists('vfs://absent.txt'));
    }

    public function testFileTypePredicates(): void
    {
        file_put_contents('vfs://file.txt', 'x');
        mkdir('vfs://dir');

        self::assertTrue(is_file('vfs://file.txt'));
        self::assertFalse(is_dir('vfs://file.txt'));
        self::assertTrue(is_dir('vfs://dir'));
        self::assertFalse(is_file('vfs://dir'));
        self::assertSame('file', filetype('vfs://file.txt'));
        self::assertSame('dir', filetype('vfs://dir'));
    }

    public function testStatFieldsOfRegularFile(): void
    {
        file_put_contents('vfs://stat.txt', '0123456789');
        $stat = self::statOf('vfs://stat.txt');

        self::assertSame(10, $stat['size']);
        self::assertSame(0o100000, $stat['mode'] & 0o170000);
        self::assertSame($this->fs->device, $stat['dev']);
        self::assertSame($this->fs->user, $stat['uid']);
        self::assertSame($this->fs->group, $stat['gid']);
        self::assertGreaterThan(0, $stat['ino']);
        self::assertSame(1, $stat['nlink']);
        self::assertSame($stat[7], $stat['size']);
        self::assertEqualsWithDelta(time(), $stat['mtime'], 5);
    }

    public function testDirectoryLinkCountReflectsSubdirectories(): void
    {
        mkdir('vfs://parent');
        mkdir('vfs://parent/one');
        mkdir('vfs://parent/two');
        file_put_contents('vfs://parent/file.txt', 'x');

        self::assertSame(4, self::statOf('vfs://parent')['nlink']);
    }

    public function testNewFilePermissionsHonorUmask(): void
    {
        $previousUmask = umask(0o022);
        try {
            file_put_contents('vfs://perm.txt', 'x');
        } finally {
            umask($previousUmask);
        }

        self::assertSame(0o644, self::statOf('vfs://perm.txt')['mode'] & 0o777);
    }

    public function testInodesAreUniquePerNode(): void
    {
        file_put_contents('vfs://one.txt', '1');
        file_put_contents('vfs://two.txt', '2');

        self::assertNotSame(
            self::statOf('vfs://one.txt')['ino'],
            self::statOf('vfs://two.txt')['ino'],
        );
        self::assertSame(fileinode('vfs://one.txt'), self::statOf('vfs://one.txt')['ino']);
    }

    public function testFstatMatchesUrlStat(): void
    {
        file_put_contents('vfs://both.txt', 'abc');

        $handle = self::open('vfs://both.txt', 'r');
        $fstat  = fstat($handle);
        fclose($handle);
        self::assertIsArray($fstat);

        $urlStat = self::statOf('vfs://both.txt');
        self::assertSame($urlStat['ino'], $fstat['ino']);
        self::assertSame($urlStat['size'], $fstat['size']);
        self::assertSame($urlStat['mode'], $fstat['mode']);
    }

    public function testTouchCreatesFileAndSetsTimes(): void
    {
        self::assertTrue(touch('vfs://touched.txt', 1_000_000_000, 2_000_000_000));
        clearstatcache();

        self::assertTrue(file_exists('vfs://touched.txt'));
        self::assertSame(1_000_000_000, filemtime('vfs://touched.txt'));
        self::assertSame(2_000_000_000, fileatime('vfs://touched.txt'));
    }

    public function testTouchUpdatesExistingFile(): void
    {
        file_put_contents('vfs://old.txt', 'x');
        self::assertTrue(touch('vfs://old.txt', 500_000_000));
        clearstatcache();

        self::assertSame(500_000_000, filemtime('vfs://old.txt'));
        self::assertSame('x', file_get_contents('vfs://old.txt'));
    }

    public function testWriteUpdatesModificationTime(): void
    {
        file_put_contents('vfs://times.txt', 'x');
        touch('vfs://times.txt', 100);
        clearstatcache();
        self::assertSame(100, filemtime('vfs://times.txt'));

        file_put_contents('vfs://times.txt', 'y', FILE_APPEND);
        clearstatcache();
        self::assertEqualsWithDelta(time(), filemtime('vfs://times.txt'), 5);
    }

    public function testIsReadableAndIsWritable(): void
    {
        file_put_contents('vfs://rw.txt', 'x');

        self::assertTrue(is_readable('vfs://rw.txt'));
        self::assertTrue(is_writable('vfs://rw.txt'));
    }

    public function testSplFileInfoIntegration(): void
    {
        file_put_contents('vfs://info.txt', 'twelve bytes');

        $info = new \SplFileInfo('vfs://info.txt');
        self::assertSame(12, $info->getSize());
        self::assertTrue($info->isFile());
        self::assertFalse($info->isDir());
        self::assertSame('txt', $info->getExtension());
    }

    public function testStatOnMissingFileFailsQuietly(): void
    {
        [$stat, $warnings] = self::capture(static fn (): mixed => stat('vfs://missing'));

        self::assertFalse($stat);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('stat failed', $warnings[0]);
    }
}
