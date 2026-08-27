<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

use Go\VirtualFileSystem\Path;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizationCases(): iterable
    {
        yield 'root'               => ['/', '/'];
        yield 'empty'              => ['', '/'];
        yield 'simple'             => ['/a/b', '/a/b'];
        yield 'missing lead slash' => ['a/b', '/a/b'];
        yield 'double slashes'     => ['/a//b///c', '/a/b/c'];
        yield 'trailing slash'     => ['/a/b/', '/a/b'];
        yield 'single dots'        => ['/a/./b/.', '/a/b'];
        yield 'parent refs'        => ['/a/b/../c', '/a/c'];
        yield 'above root clamps'  => ['/../../a', '/a'];
        yield 'backslashes'        => ['\\a\\b', '/a/b'];
        yield 'mixed'              => ['/a/../a/./b//', '/a/b'];
    }

    #[DataProvider('normalizationCases')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, Path::normalize($input));
    }

    public function testParseUrl(): void
    {
        self::assertSame(['vfs', '/a/b'], Path::parseUrl('vfs://a/b'));
        self::assertSame(['vfs', '/'], Path::parseUrl('vfs://'));
        self::assertSame(['my-fs', '/x'], Path::parseUrl('my-fs://x/../x'));
        self::assertSame(['', '/plain/path'], Path::parseUrl('/plain/path'));
    }

    public function testSegments(): void
    {
        self::assertSame([], Path::segments('/'));
        self::assertSame(['a', 'b'], Path::segments('/a//b/'));
    }

    public function testParent(): void
    {
        self::assertSame('/', Path::parent('/a'));
        self::assertSame('/a', Path::parent('/a/b'));
        self::assertSame('/', Path::parent('/'));
    }

    public function testBaseName(): void
    {
        self::assertSame('b', Path::baseName('/a/b'));
        self::assertSame('', Path::baseName('/'));
    }

    public function testResolveTarget(): void
    {
        self::assertSame('/etc/config', Path::resolveTarget('/anywhere', '/etc/config'));
        self::assertSame('/a/config', Path::resolveTarget('/a/b', '../config'));
        self::assertSame('/a/b/file', Path::resolveTarget('/a/b', 'file'));
    }
}
