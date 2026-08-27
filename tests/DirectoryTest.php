<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

final class DirectoryTest extends VfsTestCase
{
    public function testMkdirCreatesDirectory(): void
    {
        self::assertTrue(mkdir('vfs://data'));
        self::assertTrue(is_dir('vfs://data'));
        self::assertFalse(is_file('vfs://data'));
    }

    public function testMkdirRecursive(): void
    {
        self::assertTrue(mkdir('vfs://a/b/c', 0o777, true));
        self::assertTrue(is_dir('vfs://a'));
        self::assertTrue(is_dir('vfs://a/b'));
        self::assertTrue(is_dir('vfs://a/b/c'));
    }

    public function testMkdirWithoutRecursionRequiresParent(): void
    {
        [$result, $warnings] = self::capture(static fn (): bool => mkdir('vfs://no/parent'));

        self::assertFalse($result);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('No such file or directory', $warnings[0]);
    }

    public function testMkdirOverExistingPathFails(): void
    {
        mkdir('vfs://dup');

        [$result, $warnings] = self::capture(static fn (): bool => mkdir('vfs://dup'));

        self::assertFalse($result);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('File exists', $warnings[0]);
    }

    public function testMkdirAppliesUmask(): void
    {
        $previousUmask = umask(0o027);
        try {
            mkdir('vfs://masked', 0o777);
        } finally {
            umask($previousUmask);
        }

        self::assertSame(0o750, self::statOf('vfs://masked')['mode'] & 0o777);
    }

    public function testRmdirRemovesEmptyDirectory(): void
    {
        mkdir('vfs://gone');

        self::assertTrue(rmdir('vfs://gone'));
        self::assertFalse(file_exists('vfs://gone'));
    }

    public function testRmdirRefusesNonEmptyDirectory(): void
    {
        mkdir('vfs://full');
        file_put_contents('vfs://full/file.txt', 'x');

        [$result, $warnings] = self::capture(static fn (): bool => rmdir('vfs://full'));

        self::assertFalse($result);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Directory not empty', $warnings[0]);
    }

    public function testRmdirOnFileFails(): void
    {
        file_put_contents('vfs://plain.txt', 'x');

        [$result, $warnings] = self::capture(static fn (): bool => rmdir('vfs://plain.txt'));

        self::assertFalse($result);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Not a directory', $warnings[0]);
    }

    public function testRmdirOnMissingPathFails(): void
    {
        [$result, $warnings] = self::capture(static fn (): bool => rmdir('vfs://missing'));

        self::assertFalse($result);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('No such file or directory', $warnings[0]);
    }

    public function testScandirListsDotEntriesAndChildren(): void
    {
        mkdir('vfs://dir');
        file_put_contents('vfs://dir/b.txt', 'b');
        file_put_contents('vfs://dir/a.txt', 'a');
        mkdir('vfs://dir/sub');

        self::assertSame(['.', '..', 'a.txt', 'b.txt', 'sub'], scandir('vfs://dir'));
        self::assertSame(['sub', 'b.txt', 'a.txt', '..', '.'], scandir('vfs://dir', SCANDIR_SORT_DESCENDING));
    }

    public function testReaddirIteration(): void
    {
        mkdir('vfs://dir');
        file_put_contents('vfs://dir/one.txt', '1');
        file_put_contents('vfs://dir/two.txt', '2');

        $handle = opendir('vfs://dir');
        self::assertIsResource($handle);
        assert(is_resource($handle));

        $entries = [];
        while (($entry = readdir($handle)) !== false) {
            $entries[] = $entry;
        }
        self::assertSame(['.', '..', 'one.txt', 'two.txt'], $entries);

        rewinddir($handle);
        self::assertSame('.', readdir($handle));
        closedir($handle);
    }

    public function testOpendirOnFileFails(): void
    {
        file_put_contents('vfs://file.txt', 'x');

        [$handle, $warnings] = self::capture(static fn (): mixed => opendir('vfs://file.txt'));

        self::assertFalse($handle);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Not a directory', $warnings[0]);
    }

    public function testOpendirOnMissingPathFails(): void
    {
        [$handle, $warnings] = self::capture(static fn (): mixed => opendir('vfs://missing'));

        self::assertFalse($handle);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('No such file or directory', $warnings[0]);
    }

    public function testRootDirectoryIsListable(): void
    {
        file_put_contents('vfs://top.txt', 'x');

        self::assertSame(['.', '..', 'top.txt'], scandir('vfs://'));
    }

    public function testDirectoryIteratorWorks(): void
    {
        mkdir('vfs://it');
        file_put_contents('vfs://it/a.txt', 'a');
        mkdir('vfs://it/nested');

        $names = [];
        foreach (new \FilesystemIterator('vfs://it', \FilesystemIterator::SKIP_DOTS) as $item) {
            assert($item instanceof \SplFileInfo);
            $names[$item->getFilename()] = $item->isDir();
        }
        ksort($names);

        self::assertSame(['a.txt' => false, 'nested' => true], $names);
    }

    public function testRecursiveIteratorTraversesTree(): void
    {
        mkdir('vfs://tree/branch/leaf', 0o777, true);
        file_put_contents('vfs://tree/root.txt', 'r');
        file_put_contents('vfs://tree/branch/leaf/deep.txt', 'd');

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator('vfs://tree', \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $item) {
            assert($item instanceof \SplFileInfo);
            $found[] = $item->getFilename();
        }
        sort($found);

        self::assertSame(['deep.txt', 'root.txt'], $found);
    }
}
