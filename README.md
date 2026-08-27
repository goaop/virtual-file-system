# Virtual File System

> A blazing-fast, in-memory virtual filesystem for PHP 8.4+ — the modern replacement for `adlawson/vfs`.

[![CI](https://github.com/goaop/virtual-file-system/actions/workflows/ci.yml/badge.svg)](https://github.com/goaop/virtual-file-system/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/goaop/virtual-file-system.svg)](https://packagist.org/packages/goaop/virtual-file-system)
[![Total Downloads](https://img.shields.io/packagist/dt/goaop/virtual-file-system.svg)](https://packagist.org/packages/goaop/virtual-file-system)
[![PHP Version](https://img.shields.io/badge/php-8.4%20%7C%208.5%20%7C%208.6-777BB4.svg?logo=php&logoColor=white)](https://packagist.org/packages/goaop/virtual-file-system)
[![PHPStan Level 10](https://img.shields.io/badge/PHPStan-level%2010-brightgreen.svg)](https://phpstan.org/)
[![License](https://img.shields.io/packagist/l/goaop/virtual-file-system.svg)](LICENSE)

Mount a complete POSIX-like filesystem that lives **entirely in memory** and drive it
with the PHP functions you already know — `fopen()`, `file_put_contents()`, `mkdir()`,
`rename()`, `scandir()`, `stat()`, `flock()`, `chmod()`… No temp directories to clean
up, no disk I/O in your test suite, no leftover state between tests. Just mount,
run, unmount.

```php
use Go\VirtualFileSystem\FileSystem;

$fs = FileSystem::mount();

file_put_contents('vfs://app/config.php', '<?php return ["debug" => true];');
$config = include 'vfs://app/config.php';   // yes, include works!

$fs->unmount();
```

## Why this library?

The venerable [`adlawson/vfs`](https://github.com/adlawson/php-vfs) served the PHP
community well, but it has been unmaintained for years and throws deprecation
notices on every modern PHP version. This package is its spiritual successor,
redesigned from scratch for the PHP 8.4+ era:

|                                     | goaop/virtual-file-system | adlawson/vfs |
|-------------------------------------|:--:|:--:|
| PHP 8.4 / 8.5 / 8.6 support         | ✅ | ❌ |
| Zero deprecations, warnings, notices | ✅ | ❌ |
| Actively maintained                 | ✅ | ❌ |
| PHPStan level 10 (max), fully typed | ✅ | ❌ |
| POSIX permission & ownership model  | ✅ | ⚠️ partial |
| Symbolic links (relative, chained, loop-safe) | ✅ | ❌ |
| Advisory locking (`flock`)          | ✅ | ⚠️ partial |
| Disk quota simulation ("disk full" testing) | ✅ | ❌ |
| Multiple independent mounts         | ✅ | ✅ |
| `include`/`require` from the VFS    | ✅ | ✅ |

## Feature highlights

- **Complete stream wrapper** — every fopen mode (`r`, `r+`, `w`, `w+`, `a`, `a+`,
  `x`, `x+`, `c`, `c+`, with `b`/`t`/`e` flags), seeking past EOF with zero-fill,
  `ftruncate()`, `fstat()`, `stream_copy_to_stream()`, `readfile()`, `file()`,
  `SplFileInfo`, `RecursiveDirectoryIterator` — it all just works.
- **Real POSIX semantics** — inode numbers, link counts, `atime`/`mtime`/`ctime`,
  umask-aware permissions, uid/gid ownership, root-bypass rules, and files that
  stay readable through open handles after `unlink()` — exactly like a real OS.
- **Failure-path testing made easy** — simulate *Permission denied*, *No space
  left on device*, *Directory not empty* and friends deterministically, without
  `sudo`, `chattr` or fragile tmpfs tricks.
- **Symbolic links** — absolute and relative targets, chained links, dangling
  links, and ELOOP-style cycle detection.
- **Advisory locks** — `flock()` with `LOCK_SH`/`LOCK_EX`/`LOCK_NB` semantics per
  file, automatically released on `fclose()`.
- **Quotas** — cap the filesystem size and watch writes shorten and fail exactly
  like a full disk.
- **Fully isolated** — mount as many filesystems as you like under different
  schemes; each has its own tree, owner, quota and device number.

## Installation

```bash
composer require --dev goaop/virtual-file-system
```

Requires PHP ≥ 8.4. No extensions, no dependencies.

## 60-second tour

### Files and directories

```php
use Go\VirtualFileSystem\FileSystem;

$fs = FileSystem::mount();               // registers the vfs:// scheme

mkdir('vfs://var/cache', 0755, recursive: true);
file_put_contents('vfs://var/cache/entry.json', '{"hit":1}');

is_dir('vfs://var/cache');               // true
scandir('vfs://var');                    // ['.', '..', 'cache']
filesize('vfs://var/cache/entry.json');  // 9

rename('vfs://var/cache', 'vfs://var/cache-old');
unlink('vfs://var/cache-old/entry.json');
rmdir('vfs://var/cache-old');

$fs->unmount();                          // clean slate — nothing touched your disk
```

### Seeding fixtures with the fluent API

```php
$fs = FileSystem::mount();

// The API bypasses permission checks — perfect for arranging test fixtures.
$fs->createDirectory('/etc/app', permissions: 0o500);
$fs->createFile('/etc/app/secrets.env', 'TOKEN=abc123', permissions: 0o600);
$fs->createSymlink('/etc/app/current', '/etc/app');

// Build URLs without string concatenation:
$url = $fs->path('/etc/app/secrets.env');   // "vfs://etc/app/secrets.env"
```

### Testing permission failures (even when CI runs as root)

```php
$fs = FileSystem::mount();
$fs->createFile('/protected.txt', 'top secret', permissions: 0o600);
$fs->find('/protected.txt')?->chown(0);   // owned by root

$fs->user = 1000;                         // now pretend we are an ordinary user

fopen('vfs://protected.txt', 'r');        // false + "Permission denied" warning
```

### Testing "disk full" behavior

```php
$fs = FileSystem::mount();
$fs->quota = 1024;                        // a whole kilobyte of disk space

$written = file_put_contents('vfs://big.log', str_repeat('x', 4096));
// => 1024 — short write, exactly like a full partition

$fs->availableSpace;                      // 0
unlink('vfs://big.log');
$fs->availableSpace;                      // 1024 — space reclaimed
```

### Locks

```php
$a = fopen('vfs://queue.dat', 'c+');
$b = fopen('vfs://queue.dat', 'c+');

flock($a, LOCK_EX);                       // true
flock($b, LOCK_EX | LOCK_NB);             // false — already locked
fclose($a);                               // lock auto-released
flock($b, LOCK_EX | LOCK_NB);             // true
```

### Multiple isolated filesystems

```php
$app  = FileSystem::mount('app');
$data = FileSystem::mount('data');

file_put_contents('app://index.php', '<?php echo "hi";');
file_put_contents('data://users.csv', "id,name\n1,Ada");

FileSystem::unmountAll();
```

## API reference

| Method | Description |
|---|---|
| `FileSystem::mount(string $scheme = 'vfs'): FileSystem` | Create + register a filesystem under `scheme://` |
| `FileSystem::get(string $scheme): ?FileSystem` | Fetch a mounted filesystem by scheme |
| `FileSystem::unmountAll(): void` | Unmount every mounted filesystem |
| `$fs->unmount(): void` | Unregister the wrapper (tree stays inspectable) |
| `$fs->path(string $path = '/'): string` | Build a `scheme://…` URL from a virtual path |
| `$fs->createFile(string $path, string $content = '', int $permissions = 0o644): File` | Seed a file (creates parents) |
| `$fs->createDirectory(string $path, int $permissions = 0o777, bool $recursive = false): Directory` | Seed a directory |
| `$fs->createSymlink(string $path, string $target): SymbolicLink` | Create a symbolic link |
| `$fs->find(string $path, bool $followFinalLink = true): ?Node` | Inspect any node (lstat-style with `false`) |
| `$fs->root` | The root `Directory` node (readonly) |
| `$fs->scheme` / `$fs->device` / `$fs->isMounted` | Mount identity (readonly) |
| `$fs->quota = $bytes` / `$fs->usedSpace` / `$fs->availableSpace` | Disk-space simulation (`-1` = unlimited) |
| `$fs->user = $uid` / `$fs->group = $gid` | Identity used for permission checks |

Nodes (`File`, `Directory`, `SymbolicLink` — all implementing the `Node`
interface, whose metadata surface is declared as interface property hooks)
expose their metadata as typed properties — `$size`, `$mode`, `$permissions`, `$uid`, `$gid`, `$type`,
`$content`, `$children`, `$target` — plus intention-revealing methods
(`chmod()`, `chown()`, `chgrp()`, `touch()`) for direct fixture surgery.

## Built on modern PHP

The codebase is a showcase of PHP 8.4+ done right:

- **Property hooks** — `File::$content` keeps `mtime` honest on every
  assignment; `FileSystem::$quota` validates itself; `$usedSpace`,
  `$availableSpace`, `$mode` and `$size` are computed, virtual properties
- **Interface property declarations** — the `Node` interface declares its whole
  metadata surface as hooked properties (`public NodeType $type { get; }`,
  `public int $size { get; }`, …) — no getter methods anywhere
- **Asymmetric visibility** — metadata reads like `$node->uid` are public
  while writes stay guarded (`public private(set)`)
- **Readonly classes & promoted constructors** — the fopen mode parser is an
  immutable `OpenMode` value object built with named arguments
- **Backed enums** — POSIX file type bits live in a `NodeType` enum
- **`array_any()`, first-class `match`, named arguments** throughout

No annotations pretending to be types, no magic `__get` — everything is
natively typed and enforced by the engine itself.

## Known limitations

A handful of PHP functions never consult userland stream wrappers — this applies
to *every* VFS library, not just this one:

- `glob()` — use `FilesystemIterator` with a wildcard filter instead
- `realpath()` — returns `false`; paths are already normalized by the wrapper
- `symlink()`, `link()`, `readlink()` — use `$fs->createSymlink()` and `$fs->find($path, false)?->target()`
- `chdir()` / relative paths — always use full `vfs://…` URLs
- `tempnam()` — falls back to the real temp dir; create files directly instead
- `exec()` and other process-level functions — child processes cannot see PHP userland wrappers

## Quality

- **PHPStan level 10** (the maximum) across the whole codebase — src *and* tests
- **141 tests / 400+ assertions** running with `error_reporting=-1` and PHPUnit
  configured to fail on any warning, notice or deprecation
- Verified clean on **PHP 8.4, 8.5 and 8.6** on every push

```bash
composer test      # run the test suite
composer phpstan   # run static analysis
composer check     # both
```

## Contributing

Issues and pull requests are welcome! Please make sure `composer check` passes
on PHP 8.4+ before submitting.

## License

Released under the [MIT license](LICENSE).
