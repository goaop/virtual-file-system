<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

use Go\VirtualFileSystem\FileSystem;

final class RenameTest extends VfsTestCase
{
    public function testRenameFile(): void
    {
        file_put_contents('vfs://old.txt', 'content');

        self::assertTrue(rename('vfs://old.txt', 'vfs://new.txt'));
        self::assertFalse(file_exists('vfs://old.txt'));
        self::assertSame('content', file_get_contents('vfs://new.txt'));
    }

    public function testRenameMovesBetweenDirectories(): void
    {
        mkdir('vfs://from');
        mkdir('vfs://to');
        file_put_contents('vfs://from/file.txt', 'moving');

        self::assertTrue(rename('vfs://from/file.txt', 'vfs://to/file.txt'));
        self::assertSame('moving', file_get_contents('vfs://to/file.txt'));
        self::assertSame(['.', '..'], scandir('vfs://from'));
    }

    public function testRenameOverwritesExistingFile(): void
    {
        file_put_contents('vfs://src.txt', 'winner');
        file_put_contents('vfs://dst.txt', 'loser');

        self::assertTrue(rename('vfs://src.txt', 'vfs://dst.txt'));
        self::assertSame('winner', file_get_contents('vfs://dst.txt'));
    }

    public function testRenameDirectoryKeepsContents(): void
    {
        mkdir('vfs://olddir/nested', 0o777, true);
        file_put_contents('vfs://olddir/nested/deep.txt', 'kept');

        self::assertTrue(rename('vfs://olddir', 'vfs://newdir'));
        self::assertSame('kept', file_get_contents('vfs://newdir/nested/deep.txt'));
        self::assertFalse(file_exists('vfs://olddir'));
    }

    public function testRenameDirectoryOverEmptyDirectory(): void
    {
        mkdir('vfs://src');
        file_put_contents('vfs://src/data.txt', 'x');
        mkdir('vfs://empty');

        self::assertTrue(rename('vfs://src', 'vfs://empty'));
        self::assertSame('x', file_get_contents('vfs://empty/data.txt'));
    }

    public function testRenameDirectoryOverNonEmptyDirectoryFails(): void
    {
        mkdir('vfs://src');
        mkdir('vfs://busy');
        file_put_contents('vfs://busy/occupant.txt', 'x');

        [$result, $warnings] = self::capture(static fn (): bool => rename('vfs://src', 'vfs://busy'));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Directory not empty', $warnings[0]);
    }

    public function testRenameFileOverDirectoryFails(): void
    {
        file_put_contents('vfs://file.txt', 'x');
        mkdir('vfs://dir');

        [$result, $warnings] = self::capture(static fn (): bool => rename('vfs://file.txt', 'vfs://dir'));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Is a directory', $warnings[0]);
    }

    public function testRenameDirectoryOverFileFails(): void
    {
        mkdir('vfs://dir');
        file_put_contents('vfs://file.txt', 'x');

        [$result, $warnings] = self::capture(static fn (): bool => rename('vfs://dir', 'vfs://file.txt'));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Not a directory', $warnings[0]);
    }

    public function testRenameMissingSourceFails(): void
    {
        [$result, $warnings] = self::capture(static fn (): bool => rename('vfs://ghost', 'vfs://anywhere'));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('No such file or directory', $warnings[0]);
    }

    public function testRenameDirectoryIntoItselfFails(): void
    {
        mkdir('vfs://outer');

        [$result, $warnings] = self::capture(static fn (): bool => rename('vfs://outer', 'vfs://outer/inner'));

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Invalid argument', $warnings[0]);
    }

    public function testRenameAcrossFileSystemsFails(): void
    {
        $other = FileSystem::mount('vfs2');
        file_put_contents('vfs://here.txt', 'x');

        try {
            [$result, $warnings] = self::capture(static fn (): bool => rename('vfs://here.txt', 'vfs2://there.txt'));
        } finally {
            $other->unmount();
        }

        self::assertFalse($result);
        self::assertNotEmpty($warnings);
    }
}
