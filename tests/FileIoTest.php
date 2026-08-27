<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

final class FileIoTest extends VfsTestCase
{
    public function testFilePutAndGetContents(): void
    {
        self::assertSame(11, file_put_contents('vfs://hello.txt', 'Hello, VFS!'));
        self::assertSame('Hello, VFS!', file_get_contents('vfs://hello.txt'));
    }

    public function testBinarySafety(): void
    {
        $binary = random_bytes(4096) . "\0\0" . random_bytes(100);
        file_put_contents('vfs://blob.bin', $binary);

        self::assertSame($binary, file_get_contents('vfs://blob.bin'));
        self::assertSame(strlen($binary), filesize('vfs://blob.bin'));
    }

    public function testReadModeFailsForMissingFile(): void
    {
        [$handle, $warnings] = self::capture(static fn (): mixed => fopen('vfs://missing.txt', 'r'));

        self::assertFalse($handle);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('No such file or directory', $warnings[0]);
    }

    public function testWriteModeTruncatesExistingContent(): void
    {
        file_put_contents('vfs://file.txt', 'long initial content');

        $handle = self::open('vfs://file.txt', 'w');
        fwrite($handle, 'new');
        fclose($handle);

        self::assertSame('new', file_get_contents('vfs://file.txt'));
    }

    public function testCModeKeepsExistingContent(): void
    {
        file_put_contents('vfs://file.txt', 'ABCDEF');

        $handle = self::open('vfs://file.txt', 'c');
        self::assertSame(0, ftell($handle));
        fwrite($handle, 'xy');
        fclose($handle);

        self::assertSame('xyCDEF', file_get_contents('vfs://file.txt'));
    }

    public function testXModeFailsWhenFileExists(): void
    {
        file_put_contents('vfs://file.txt', 'existing');

        [$handle, $warnings] = self::capture(static fn (): mixed => fopen('vfs://file.txt', 'x'));

        self::assertFalse($handle);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('File exists', $warnings[0]);
    }

    public function testXModeCreatesNewFile(): void
    {
        $handle = self::open('vfs://fresh.txt', 'x');
        fwrite($handle, 'created');
        fclose($handle);

        self::assertSame('created', file_get_contents('vfs://fresh.txt'));
    }

    public function testIllegalModeIsRejected(): void
    {
        [$handle, $warnings] = self::capture(static fn (): mixed => fopen('vfs://file.txt', 'z'));

        self::assertFalse($handle);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Illegal mode', $warnings[0]);
    }

    public function testAppendModeAlwaysWritesAtEnd(): void
    {
        file_put_contents('vfs://log.txt', 'line1');

        $handle = self::open('vfs://log.txt', 'a');
        fseek($handle, 0);
        fwrite($handle, '+line2');
        fclose($handle);

        self::assertSame('line1+line2', file_get_contents('vfs://log.txt'));
    }

    public function testAppendReadMode(): void
    {
        file_put_contents('vfs://log.txt', 'abc');

        $handle = self::open('vfs://log.txt', 'a+');
        self::assertSame(3, ftell($handle));
        fseek($handle, 0);
        self::assertSame('abc', fread($handle, 10));
        fwrite($handle, 'def');
        fseek($handle, 0);
        self::assertSame('abcdef', fread($handle, 10));
        fclose($handle);
    }

    public function testReadWriteRoundTrip(): void
    {
        $handle = self::open('vfs://data.txt', 'w+');
        fwrite($handle, 'The quick brown fox');
        rewind($handle);

        self::assertSame('The quick', fread($handle, 9));
        self::assertSame(9, ftell($handle));
        self::assertFalse(feof($handle));
        self::assertSame(' brown fox', fread($handle, 100));
        self::assertTrue(feof($handle));
        fclose($handle);
    }

    public function testSeekBeyondEndZeroPads(): void
    {
        $handle = self::open('vfs://sparse.bin', 'w');
        fseek($handle, 5);
        fwrite($handle, 'X');
        fclose($handle);

        self::assertSame("\0\0\0\0\0X", file_get_contents('vfs://sparse.bin'));
    }

    public function testSeekWhenceVariants(): void
    {
        $handle = self::open('vfs://seek.txt', 'w+');
        fwrite($handle, '0123456789');

        self::assertSame(0, fseek($handle, 2, SEEK_SET));
        self::assertSame('2', fread($handle, 1));
        self::assertSame(0, fseek($handle, 3, SEEK_CUR));
        self::assertSame('6', fread($handle, 1));
        self::assertSame(0, fseek($handle, -1, SEEK_END));
        self::assertSame('9', fread($handle, 1));
        self::assertSame(-1, fseek($handle, -100, SEEK_SET));
        fclose($handle);
    }

    public function testTruncateShrinksAndGrows(): void
    {
        $handle = self::open('vfs://trunc.txt', 'w+');
        fwrite($handle, 'abcdefgh');

        self::assertTrue(ftruncate($handle, 3));
        self::assertSame('abc', file_get_contents('vfs://trunc.txt'));

        self::assertTrue(ftruncate($handle, 5));
        self::assertSame("abc\0\0", file_get_contents('vfs://trunc.txt'));
        fclose($handle);
    }

    public function testWritesAreVisibleAcrossHandles(): void
    {
        $writer = self::open('vfs://shared.txt', 'w');
        $reader = self::open('vfs://shared.txt', 'r');

        fwrite($writer, 'payload');
        self::assertSame('payload', fread($reader, 100));

        fclose($writer);
        fclose($reader);
    }

    public function testUnlinkedFileRemainsUsableThroughOpenHandle(): void
    {
        $handle = self::open('vfs://ghost.txt', 'w+');
        fwrite($handle, 'still alive');
        unlink('vfs://ghost.txt');

        self::assertFalse(file_exists('vfs://ghost.txt'));
        rewind($handle);
        self::assertSame('still alive', fread($handle, 100));
        fclose($handle);
    }

    public function testStreamCopyToStream(): void
    {
        file_put_contents('vfs://src.txt', str_repeat('xyz', 1000));

        $source = self::open('vfs://src.txt', 'r');
        $target = self::open('vfs://dst.txt', 'w');
        self::assertSame(3000, stream_copy_to_stream($source, $target));
        fclose($source);
        fclose($target);

        self::assertSame(str_repeat('xyz', 1000), file_get_contents('vfs://dst.txt'));
    }

    public function testCopyFunction(): void
    {
        file_put_contents('vfs://original.txt', 'copy me');

        self::assertTrue(copy('vfs://original.txt', 'vfs://duplicate.txt'));
        self::assertSame('copy me', file_get_contents('vfs://duplicate.txt'));
        self::assertSame('copy me', file_get_contents('vfs://original.txt'));
    }

    public function testFileFunctionSplitsLines(): void
    {
        file_put_contents('vfs://lines.txt', "one\ntwo\nthree\n");

        self::assertSame(
            ['one', 'two', 'three'],
            file('vfs://lines.txt', FILE_IGNORE_NEW_LINES),
        );
    }

    public function testFgetsReadsLineByLine(): void
    {
        file_put_contents('vfs://lines.txt', "first\nsecond\n");

        $handle = self::open('vfs://lines.txt', 'r');
        self::assertSame("first\n", fgets($handle));
        self::assertSame("second\n", fgets($handle));
        self::assertFalse(fgets($handle));
        fclose($handle);
    }

    public function testReadfileEchoesContent(): void
    {
        file_put_contents('vfs://out.txt', 'streamed');

        ob_start();
        $bytes = readfile('vfs://out.txt');
        $output = ob_get_clean();

        self::assertSame(8, $bytes);
        self::assertSame('streamed', $output);
    }

    public function testFilePutContentsAppendFlag(): void
    {
        file_put_contents('vfs://append.txt', 'a');
        file_put_contents('vfs://append.txt', 'b', FILE_APPEND);

        self::assertSame('ab', file_get_contents('vfs://append.txt'));
    }

    public function testFileGetContentsOffsetAndLength(): void
    {
        file_put_contents('vfs://slice.txt', '0123456789');

        self::assertSame('345', file_get_contents('vfs://slice.txt', false, null, 3, 3));
    }

    public function testOpeningDirectoryAsFileFails(): void
    {
        mkdir('vfs://dir');

        [$handle, $warnings] = self::capture(static fn (): mixed => fopen('vfs://dir', 'r'));

        self::assertFalse($handle);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Is a directory', $warnings[0]);
    }
}
