<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem;

use Go\VirtualFileSystem\Exception\OperationException;
use Go\VirtualFileSystem\Exception\RegistrationException;
use Go\VirtualFileSystem\Node\Directory;
use Go\VirtualFileSystem\Node\File;
use Go\VirtualFileSystem\Node\Node;
use Go\VirtualFileSystem\Node\SymbolicLink;

/**
 * An in-memory virtual filesystem exposed to PHP through a stream wrapper.
 *
 * Mounting registers a URL scheme (default "vfs://") that every native
 * filesystem function — fopen(), file_get_contents(), mkdir(), rename(),
 * scandir(), stat(), touch(), chmod() and friends — transparently operates on.
 *
 * ```php
 * $fs = FileSystem::mount();
 * file_put_contents($fs->path('/hello.txt'), 'Hello!');
 * echo file_get_contents('vfs://hello.txt'); // Hello!
 * $fs->unmount();
 * ```
 */
final class FileSystem
{
    /**
     * Maximum number of symbolic links followed during path resolution,
     * mirroring the Linux kernel's limit before returning ELOOP.
     */
    private const int MAX_LINK_DEPTH = 40;

    /**
     * @var array<string, self> Mounted filesystems keyed by URL scheme
     */
    private static array $mounted = [];

    private static int $nextDevice = 1;

    /**
     * Pseudo block device number reported in stat() results.
     */
    public readonly int $device;

    public readonly Directory $root;

    /**
     * User id used for permission checks. Defaults to the real process uid;
     * assign a non-zero uid to let a root-run test suite exercise
     * "Permission denied" paths.
     */
    public int $user;

    /**
     * Group id used for permission checks.
     */
    public int $group;

    /**
     * Total content bytes the filesystem may hold; -1 lifts the limit.
     * Writes beyond the quota behave like a full disk: short writes and
     * "No space left on device" warnings.
     */
    public int $quota = -1 {
        set => max(-1, $value);
    }

    /**
     * Total number of content bytes currently stored in regular files.
     */
    public int $usedSpace {
        get => $this->directoryUsage($this->root);
    }

    /**
     * Bytes still writable before the quota is exhausted (PHP_INT_MAX when unlimited).
     */
    public int $availableSpace {
        get => $this->quota < 0 ? PHP_INT_MAX : max(0, $this->quota - $this->usedSpace);
    }

    public bool $isMounted {
        get => (self::$mounted[$this->scheme] ?? null) === $this;
    }

    private function __construct(
        public readonly string $scheme,
    ) {
        $this->device = self::$nextDevice++;
        $this->user   = function_exists('posix_getuid') ? posix_getuid() : 0;
        $this->group  = function_exists('posix_getgid') ? posix_getgid() : 0;
        $this->root   = new Directory(0o755, $this->user, $this->group);
    }

    /**
     * Creates a fresh filesystem and registers its stream wrapper.
     *
     * @param string $scheme URL scheme to register, e.g. "vfs" for "vfs://" paths
     *
     * @throws RegistrationException When the scheme is invalid or already taken
     */
    public static function mount(string $scheme = 'vfs'): self
    {
        if (preg_match('/^[a-z][a-z0-9.+-]*$/i', $scheme) !== 1) {
            throw new RegistrationException(sprintf('"%s" is not a valid stream wrapper scheme', $scheme));
        }
        if (isset(self::$mounted[$scheme])) {
            throw new RegistrationException(sprintf('A virtual filesystem is already mounted at "%s://"', $scheme));
        }
        if (in_array($scheme, stream_get_wrappers(), true)) {
            throw new RegistrationException(sprintf('The "%s://" scheme is already claimed by another stream wrapper', $scheme));
        }
        if (!stream_wrapper_register($scheme, StreamWrapper::class)) {
            throw new RegistrationException(sprintf('Unable to register a stream wrapper for "%s://"', $scheme));
        }

        return self::$mounted[$scheme] = new self($scheme);
    }

    /**
     * Unregisters the stream wrapper and detaches this filesystem.
     *
     * The in-memory tree stays intact, so the instance can still be inspected
     * through its API after unmounting. Unmounting twice is a no-op.
     */
    public function unmount(): void
    {
        if (!$this->isMounted) {
            return;
        }
        unset(self::$mounted[$this->scheme]);
        stream_wrapper_unregister($this->scheme);
    }

    /**
     * Unmounts every currently mounted virtual filesystem.
     */
    public static function unmountAll(): void
    {
        array_map(static fn (self $fileSystem) => $fileSystem->unmount(), self::$mounted);
    }

    /**
     * Returns the filesystem mounted at the given scheme, if any.
     */
    public static function get(string $scheme): ?self
    {
        return self::$mounted[$scheme] ?? null;
    }

    /**
     * Builds a full stream URL for a virtual path: path('/a/b') => "vfs://a/b".
     */
    public function path(string $path = '/'): string
    {
        return $this->scheme . '://' . ltrim(Path::normalize($path), '/');
    }

    /**
     * Looks up the node at the given virtual path.
     *
     * @param bool $followFinalLink Whether a symbolic link at the very end of
     *                              the path is resolved (stat) or returned
     *                              as-is (lstat)
     */
    public function find(string $path, bool $followFinalLink = true): ?Node
    {
        return $this->resolveSegments(Path::segments($path), $followFinalLink, 0);
    }

    /**
     * Creates (or returns an existing) directory at the given path.
     *
     * This is a test-setup convenience API: it never checks permissions.
     *
     * @throws OperationException When the path or one of its parents is occupied by a non-directory
     */
    public function createDirectory(string $path, int $permissions = 0o777, bool $recursive = false): Directory
    {
        $segments = Path::segments($path);
        if ($segments === []) {
            return $this->root;
        }

        if (!$recursive) {
            $parent = $this->find(Path::parent($path));
            if (!$parent instanceof Directory) {
                throw new OperationException(sprintf('Parent of "%s" does not exist (pass $recursive: true to create it)', $path));
            }
            $name     = Path::baseName($path);
            $existing = $parent->child($name);
            if ($existing instanceof Directory) {
                return $existing;
            }
            if ($existing !== null) {
                throw new OperationException(sprintf('"%s" already exists and is not a directory', $path));
            }
            $directory = new Directory($permissions, $this->user, $this->group);
            $parent->addChild($name, $directory);

            return $directory;
        }

        $current = $this->root;
        foreach ($segments as $segment) {
            $child = $current->child($segment);
            if ($child === null) {
                $child = new Directory($permissions, $this->user, $this->group);
                $current->addChild($segment, $child);
            }
            if (!$child instanceof Directory) {
                throw new OperationException(sprintf('"%s" is occupied by a non-directory while creating "%s"', $segment, $path));
            }
            $current = $child;
        }

        return $current;
    }

    /**
     * Creates or replaces a regular file at the given path.
     *
     * Missing parent directories are created automatically. This is a
     * test-setup convenience API: it never checks permissions or quota.
     *
     * @throws OperationException When the path is occupied by a directory
     */
    public function createFile(string $path, string $content = '', int $permissions = 0o644): File
    {
        $parent = $this->createDirectory(Path::parent($path), recursive: true);
        $name   = Path::baseName($path);
        if ($name === '') {
            throw new OperationException('Cannot create a file at the filesystem root path "/"');
        }
        if ($parent->child($name) instanceof Directory) {
            throw new OperationException(sprintf('"%s" already exists and is a directory', $path));
        }

        $file          = new File($permissions, $this->user, $this->group);
        $file->content = $content;
        $parent->addChild($name, $file);

        return $file;
    }

    /**
     * Creates a symbolic link at $path pointing to $target.
     *
     * PHP's symlink() builtin never consults stream wrappers, so links are
     * created through this API instead. Both absolute ("/data/file") and
     * relative ("../file") targets are supported; dangling targets are allowed.
     *
     * @throws OperationException When the path is already occupied
     */
    public function createSymlink(string $path, string $target): SymbolicLink
    {
        $parent = $this->createDirectory(Path::parent($path), recursive: true);
        $name   = Path::baseName($path);
        if ($name === '' || $parent->hasChild($name)) {
            throw new OperationException(sprintf('Cannot create symbolic link: "%s" already exists', $path));
        }

        $link = new SymbolicLink($target, 0o777, $this->user, $this->group);
        $parent->addChild($name, $link);

        return $link;
    }

    private function directoryUsage(Directory $directory): int
    {
        return array_reduce(
            $directory->children,
            fn (int $bytes, Node $child): int => $bytes + match (true) {
                $child instanceof Directory => $this->directoryUsage($child),
                $child instanceof File      => $child->size,
                default                     => 0,
            },
            0,
        );
    }

    /**
     * @param list<string> $segments
     */
    private function resolveSegments(array $segments, bool $followFinalLink, int $depth): ?Node
    {
        if ($depth > self::MAX_LINK_DEPTH) {
            return null;
        }

        $node      = $this->root;
        $lastIndex = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if (!$node instanceof Directory) {
                return null;
            }
            $child = $node->child($segment);
            if ($child === null) {
                return null;
            }
            if ($child instanceof SymbolicLink && ($index < $lastIndex || $followFinalLink)) {
                $parentPath = '/' . implode('/', array_slice($segments, 0, $index));
                $targetPath = Path::resolveTarget($parentPath, $child->target);
                $rebased    = [...Path::segments($targetPath), ...array_slice($segments, $index + 1)];

                return $this->resolveSegments($rebased, $followFinalLink, $depth + 1);
            }
            $node = $child;
        }

        return $node;
    }
}
