<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

final class LockTest extends VfsTestCase
{
    public function testExclusiveLockBlocksOtherHandles(): void
    {
        file_put_contents('vfs://locked.txt', 'x');
        $first  = self::open('vfs://locked.txt', 'r+');
        $second = self::open('vfs://locked.txt', 'r+');

        self::assertTrue(flock($first, LOCK_EX));
        self::assertFalse(flock($second, LOCK_EX | LOCK_NB));
        self::assertFalse(flock($second, LOCK_SH | LOCK_NB));

        fclose($first);
        fclose($second);
    }

    public function testSharedLocksCoexist(): void
    {
        file_put_contents('vfs://shared.txt', 'x');
        $first  = self::open('vfs://shared.txt', 'r');
        $second = self::open('vfs://shared.txt', 'r');

        self::assertTrue(flock($first, LOCK_SH));
        self::assertTrue(flock($second, LOCK_SH | LOCK_NB));

        fclose($first);
        fclose($second);
    }

    public function testSharedLockBlocksExclusive(): void
    {
        file_put_contents('vfs://mixed.txt', 'x');
        $reader = self::open('vfs://mixed.txt', 'r');
        $writer = self::open('vfs://mixed.txt', 'r+');

        self::assertTrue(flock($reader, LOCK_SH));
        self::assertFalse(flock($writer, LOCK_EX | LOCK_NB));

        fclose($reader);
        fclose($writer);
    }

    public function testUnlockReleasesTheLock(): void
    {
        file_put_contents('vfs://cycle.txt', 'x');
        $first  = self::open('vfs://cycle.txt', 'r+');
        $second = self::open('vfs://cycle.txt', 'r+');

        self::assertTrue(flock($first, LOCK_EX));
        self::assertTrue(flock($first, LOCK_UN));
        self::assertTrue(flock($second, LOCK_EX | LOCK_NB));

        fclose($first);
        fclose($second);
    }

    public function testClosingHandleReleasesItsLock(): void
    {
        file_put_contents('vfs://auto.txt', 'x');
        $first = self::open('vfs://auto.txt', 'r+');
        self::assertTrue(flock($first, LOCK_EX));
        fclose($first);

        $second = self::open('vfs://auto.txt', 'r+');
        self::assertTrue(flock($second, LOCK_EX | LOCK_NB));
        fclose($second);
    }

    public function testLockSupportProbeSucceeds(): void
    {
        file_put_contents('vfs://probe.txt', 'x');
        $handle = self::open('vfs://probe.txt', 'r+');

        // Operation 0 is the internal probe PHP issues via stream_supports_lock()
        // before honouring LOCK_EX in file_put_contents(); it must not be refused
        self::assertTrue(stream_supports_lock($handle));

        fclose($handle);
    }

    public function testLockUpgradeAndDowngrade(): void
    {
        file_put_contents('vfs://updown.txt', 'x');
        $handle = self::open('vfs://updown.txt', 'r+');

        self::assertTrue(flock($handle, LOCK_SH));
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'sole shared holder can upgrade');
        self::assertTrue(flock($handle, LOCK_SH), 'exclusive holder can downgrade');

        $other = self::open('vfs://updown.txt', 'r');
        self::assertTrue(flock($other, LOCK_SH | LOCK_NB), 'downgraded lock is shared again');

        fclose($handle);
        fclose($other);
    }
}
