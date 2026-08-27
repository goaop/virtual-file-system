<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

final class QuotaTest extends VfsTestCase
{
    public function testUsedAndAvailableSpaceReporting(): void
    {
        self::assertSame(0, $this->fs->usedSpace());
        self::assertSame(PHP_INT_MAX, $this->fs->availableSpace());

        $this->fs->setQuota(100);
        file_put_contents('vfs://data.bin', str_repeat('a', 30));

        self::assertSame(100, $this->fs->quota());
        self::assertSame(30, $this->fs->usedSpace());
        self::assertSame(70, $this->fs->availableSpace());
    }

    public function testWriteIsShortenedAtQuotaBoundary(): void
    {
        $this->fs->setQuota(10);

        $handle = self::open('vfs://limited.bin', 'w');
        [$written, $warnings] = self::capture(static fn (): mixed => fwrite($handle, str_repeat('x', 25)));
        fclose($handle);

        self::assertSame(10, $written);
        self::assertStringContainsString('No space left on device', implode(' ', $warnings));

        self::assertSame(10, filesize('vfs://limited.bin'));
        self::assertSame(0, $this->fs->availableSpace());
    }

    public function testWriteToFullDiskWarnsAndWritesNothing(): void
    {
        $this->fs->setQuota(5);
        file_put_contents('vfs://full.bin', 'aaaaa');

        $handle = self::open('vfs://full.bin', 'a');
        [$written, $warnings] = self::capture(static fn (): mixed => fwrite($handle, 'more'));
        fclose($handle);

        self::assertNotEmpty($warnings);
        self::assertStringContainsString('No space left on device', implode(' ', $warnings));
        self::assertContains($written, [0, false], 'nothing must be written on a full disk');
        self::assertSame('aaaaa', file_get_contents('vfs://full.bin'));
    }

    public function testOverwritingWithinQuotaSucceeds(): void
    {
        $this->fs->setQuota(5);
        file_put_contents('vfs://file.bin', 'aaaaa');

        $handle = self::open('vfs://file.bin', 'c');
        self::assertSame(5, fwrite($handle, 'bbbbb'));
        fclose($handle);

        self::assertSame('bbbbb', file_get_contents('vfs://file.bin'));
    }

    public function testUnlinkFreesSpace(): void
    {
        $this->fs->setQuota(10);
        file_put_contents('vfs://a.bin', str_repeat('a', 10));
        self::assertSame(0, $this->fs->availableSpace());

        unlink('vfs://a.bin');
        self::assertSame(10, $this->fs->availableSpace());

        file_put_contents('vfs://b.bin', str_repeat('b', 10));
        self::assertSame(10, filesize('vfs://b.bin'));
    }

    public function testTruncateGrowthIsLimitedByQuota(): void
    {
        $this->fs->setQuota(10);
        $handle = self::open('vfs://grow.bin', 'w');

        self::assertFalse(ftruncate($handle, 100));
        self::assertTrue(ftruncate($handle, 10));
        fclose($handle);

        self::assertSame(10, filesize('vfs://grow.bin'));
    }

    public function testLiftingQuotaRestoresUnlimitedWrites(): void
    {
        $this->fs->setQuota(1);
        $this->fs->setQuota(-1);

        file_put_contents('vfs://big.bin', str_repeat('x', 100_000));

        self::assertSame(100_000, filesize('vfs://big.bin'));
    }
}
