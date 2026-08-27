<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

final class SymlinkTest extends VfsTestCase
{
    public function testReadThroughAbsoluteLink(): void
    {
        file_put_contents('vfs://target.txt', 'through the link');
        $this->fs->createSymlink('/link.txt', '/target.txt');

        self::assertSame('through the link', file_get_contents('vfs://link.txt'));
    }

    public function testReadThroughRelativeLink(): void
    {
        mkdir('vfs://a/b', 0o777, true);
        file_put_contents('vfs://a/data.txt', 'relative');
        $this->fs->createSymlink('/a/b/link.txt', '../data.txt');

        self::assertSame('relative', file_get_contents('vfs://a/b/link.txt'));
    }

    public function testLinkToDirectoryIsTraversable(): void
    {
        mkdir('vfs://real/sub', 0o777, true);
        file_put_contents('vfs://real/sub/file.txt', 'via dir link');
        $this->fs->createSymlink('/alias', '/real');

        self::assertTrue(is_dir('vfs://alias'));
        self::assertSame('via dir link', file_get_contents('vfs://alias/sub/file.txt'));
        self::assertContains('sub', scandir('vfs://alias') ?: []);
    }

    public function testIsLinkAndLstatSeeTheLinkItself(): void
    {
        file_put_contents('vfs://target.txt', 'some longer content here');
        $this->fs->createSymlink('/link.txt', '/target.txt');

        self::assertTrue(is_link('vfs://link.txt'));
        self::assertFalse(is_link('vfs://target.txt'));

        $lstat = lstat('vfs://link.txt');
        self::assertIsArray($lstat);
        self::assertSame(0o120000, $lstat['mode'] & 0o170000);
        self::assertSame(strlen('/target.txt'), $lstat['size']);

        self::assertSame(strlen('some longer content here'), self::statOf('vfs://link.txt')['size']);
    }

    public function testBrokenLink(): void
    {
        $this->fs->createSymlink('/dangling', '/nowhere');

        self::assertTrue(is_link('vfs://dangling'));
        self::assertFalse(file_exists('vfs://dangling'));
    }

    public function testWritingThroughBrokenLinkCreatesTarget(): void
    {
        $this->fs->createSymlink('/pointer.txt', '/created-by-link.txt');

        file_put_contents('vfs://pointer.txt', 'materialized');

        self::assertSame('materialized', file_get_contents('vfs://created-by-link.txt'));
        self::assertTrue(is_link('vfs://pointer.txt'));
    }

    public function testChainedLinks(): void
    {
        file_put_contents('vfs://end.txt', 'chain end');
        $this->fs->createSymlink('/middle', '/end.txt');
        $this->fs->createSymlink('/start', '/middle');

        self::assertSame('chain end', file_get_contents('vfs://start'));
    }

    public function testLinkLoopIsDetected(): void
    {
        $this->fs->createSymlink('/ping', '/pong');
        $this->fs->createSymlink('/pong', '/ping');

        [$content, $warnings] = self::capture(static fn (): mixed => file_get_contents('vfs://ping'));

        self::assertFalse($content);
        self::assertNotEmpty($warnings);
    }

    public function testUnlinkRemovesTheLinkNotTheTarget(): void
    {
        file_put_contents('vfs://target.txt', 'survivor');
        $this->fs->createSymlink('/link.txt', '/target.txt');

        self::assertTrue(unlink('vfs://link.txt'));
        self::assertFalse(is_link('vfs://link.txt'));
        self::assertSame('survivor', file_get_contents('vfs://target.txt'));
    }

    public function testFiletypeOfLinkViaLstatMode(): void
    {
        file_put_contents('vfs://t.txt', 'x');
        $this->fs->createSymlink('/l.txt', '/t.txt');

        self::assertSame('link', filetype('vfs://l.txt'));

        $lstat = lstat('vfs://l.txt');
        self::assertIsArray($lstat);
        self::assertSame('link', match ($lstat['mode'] & 0o170000) {
            0o120000 => 'link',
            0o040000 => 'dir',
            default  => 'file',
        });
    }
}
