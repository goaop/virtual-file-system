<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem;

use Go\VirtualFileSystem\Node\Directory;
use Go\VirtualFileSystem\Node\File;
use Go\VirtualFileSystem\Node\Node;
use Go\VirtualFileSystem\Node\SymbolicLink;

/**
 * PHP stream wrapper backing every mounted {@see FileSystem}.
 *
 * Instantiated by PHP itself — one instance per open stream or directory
 * handle. Never use this class directly; interact with the virtual
 * filesystem through native PHP functions instead.
 *
 * @internal
 */
final class StreamWrapper
{
    /**
     * Maximum hops while following symbolic links to a creation target.
     */
    private const int MAX_LINK_DEPTH = 40;

    /**
     * @var resource|null Stream context passed by PHP (unused, streams have no options)
     */
    public $context;

    private ?FileSystem $fileSystem = null;

    private ?File $file = null;

    private int $position = 0;

    private ?OpenMode $mode = null;

    /**
     * @var list<string>
     */
    private array $directoryEntries = [];

    private int $directoryPosition = 0;

    /*
     * ---------------------------------------------------------------
     *  File stream interface
     * ---------------------------------------------------------------
     */

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        // PHP strips STREAM_REPORT_ERRORS before invoking userspace wrappers
        // and silences these warnings itself under the @-operator, so failure
        // diagnostics are always raised here.
        $located = $this->locate($path);
        if ($located === null) {
            $this->error('fopen(%s): Failed to open stream: No such device', $path);

            return false;
        }
        [$fileSystem, $virtualPath] = $located;

        $openMode = OpenMode::parse($mode);
        if ($openMode === null) {
            $this->error('fopen(%s): Failed to open stream: Illegal mode "%s"', $path, $mode);

            return false;
        }

        $node = $fileSystem->find($virtualPath);
        if ($node instanceof Directory) {
            $this->error('fopen(%s): Failed to open stream: Is a directory', $path);

            return false;
        }

        if ($node instanceof File && $openMode->exclusive) {
            $this->error('fopen(%s): Failed to open stream: File exists', $path);

            return false;
        }

        if ($node === null) {
            if (!$openMode->allowsCreation) {
                $this->error('fopen(%s): Failed to open stream: No such file or directory', $path);

                return false;
            }
            $file = $this->createFileAt($fileSystem, $virtualPath, $failureReason);
            if ($file === null) {
                $this->error('fopen(%s): Failed to open stream: %s', $path, $failureReason ?? 'No such file or directory');

                return false;
            }
            $node = $file;
        } else {
            assert($node instanceof File);
            if ($openMode->readable && !$node->isReadableBy($fileSystem->user, $fileSystem->group)
                || $openMode->writable && !$node->isWritableBy($fileSystem->user, $fileSystem->group)
            ) {
                $this->error('fopen(%s): Failed to open stream: Permission denied', $path);

                return false;
            }
            if ($openMode->truncate) {
                $node->truncate(0);
            }
        }

        $this->fileSystem = $fileSystem;
        $this->file       = $node;
        $this->mode       = $openMode;
        $this->position   = $openMode->append ? $node->size : 0;

        if (($options & STREAM_USE_PATH) !== 0) {
            $openedPath = $path;
        }

        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->file === null || $this->mode?->readable !== true) {
            return '';
        }
        $data = $this->file->read($this->position, $count);
        $this->position += strlen($data);

        return $data;
    }

    public function stream_write(string $data): int
    {
        if ($this->file === null || $this->fileSystem === null || $this->mode?->writable !== true) {
            return 0;
        }
        if ($this->mode->append) {
            $this->position = $this->file->size;
        }

        if ($this->fileSystem->quota >= 0) {
            $currentSize = $this->file->size;
            $newSize     = max($this->position + strlen($data), $currentSize);
            $growth      = $newSize - $currentSize;
            $available   = $this->fileSystem->availableSpace;
            if ($growth > $available) {
                $writableLength = max(0, $currentSize + $available - $this->position);
                if ($writableLength === 0) {
                    trigger_error(
                        sprintf('fwrite(): Write of %d bytes failed: No space left on device', strlen($data)),
                        E_USER_WARNING,
                    );

                    return 0;
                }
                $data = substr($data, 0, $writableLength);
            }
        }

        $written = $this->file->write($this->position, $data);
        $this->position += $written;

        return $written;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_eof(): bool
    {
        return $this->file === null || $this->position >= $this->file->size;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->file === null) {
            return false;
        }
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->file->size + $offset,
            default  => -1,
        };
        if ($position < 0) {
            return false;
        }
        $this->position = $position;

        return true;
    }

    public function stream_truncate(int $newSize): bool
    {
        if ($this->file === null || $this->fileSystem === null || $this->mode?->writable !== true || $newSize < 0) {
            return false;
        }
        $growth = $newSize - $this->file->size;
        if ($growth > 0 && $this->fileSystem->quota >= 0 && $growth > $this->fileSystem->availableSpace) {
            return false;
        }
        $this->file->truncate($newSize);

        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        $this->file?->unlock(spl_object_id($this));
        $this->file       = null;
        $this->fileSystem = null;
        $this->mode       = null;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        if ($this->file === null || $this->fileSystem === null) {
            return false;
        }

        return $this->buildStat($this->fileSystem, $this->file);
    }

    public function stream_lock(int $operation): bool
    {
        if ($this->file === null) {
            return false;
        }
        $ownerId = spl_object_id($this);

        return match ($operation & ~LOCK_NB) {
            // Operation 0 is PHP's internal probe for lock support, issued before
            // honouring the LOCK_EX flag of file_put_contents() and friends
            0 => true,
            LOCK_SH => $this->file->lockShared($ownerId),
            LOCK_EX => $this->file->lockExclusive($ownerId),
            LOCK_UN => $this->unlockFile($ownerId),
            default => false,
        };
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    /**
     * Memory streams cannot be represented as OS-level descriptors.
     *
     * @return false
     */
    public function stream_cast(int $castAs): bool
    {
        return false;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        $located = $this->locate($path);
        if ($located === null) {
            return false;
        }
        [$fileSystem, $virtualPath] = $located;
        $node = $fileSystem->find($virtualPath);

        switch ($option) {
            case STREAM_META_TOUCH:
                if ($node === null) {
                    $node = $this->createFileAt($fileSystem, $virtualPath, $failureReason);
                    if ($node === null) {
                        trigger_error(
                            sprintf('touch(): Unable to create file %s because %s', $path, $failureReason ?? 'No such file or directory'),
                            E_USER_WARNING,
                        );

                        return false;
                    }
                }
                [$modificationTime, $accessTime] = is_array($value) ? [$value[0] ?? null, $value[1] ?? null] : [null, null];
                $node->touch(is_int($modificationTime) ? $modificationTime : null, is_int($accessTime) ? $accessTime : null);

                return true;

            case STREAM_META_OWNER:
            case STREAM_META_OWNER_NAME:
                if ($node === null) {
                    $this->error('chown(): No such file or directory');

                    return false;
                }
                if ($fileSystem->user !== 0) {
                    $this->error('chown(): Operation not permitted');

                    return false;
                }
                $uid = is_int($value) ? $value : $this->resolveUserName($value);
                if ($uid === null) {
                    $this->error('chown(): Unable to resolve user name');

                    return false;
                }
                $node->chown($uid);

                return true;

            case STREAM_META_GROUP:
            case STREAM_META_GROUP_NAME:
                if ($node === null) {
                    $this->error('chgrp(): No such file or directory');

                    return false;
                }
                if ($fileSystem->user !== 0) {
                    $this->error('chgrp(): Operation not permitted');

                    return false;
                }
                $gid = is_int($value) ? $value : $this->resolveGroupName($value);
                if ($gid === null) {
                    $this->error('chgrp(): Unable to resolve group name');

                    return false;
                }
                $node->chgrp($gid);

                return true;

            case STREAM_META_ACCESS:
                if ($node === null) {
                    $this->error('chmod(): No such file or directory');

                    return false;
                }
                if ($fileSystem->user !== 0 && $fileSystem->user !== $node->uid) {
                    $this->error('chmod(): Operation not permitted');

                    return false;
                }
                $node->chmod(is_int($value) ? $value : 0);

                return true;
        }

        return false;
    }

    /*
     * ---------------------------------------------------------------
     *  Filesystem manipulation interface
     * ---------------------------------------------------------------
     */

    public function unlink(string $path): bool
    {
        $located = $this->locate($path);
        if ($located === null) {
            return false;
        }
        [$fileSystem, $virtualPath] = $located;

        $node = $fileSystem->find($virtualPath, followFinalLink: false);
        if ($node === null) {
            $this->error('unlink(%s): No such file or directory', $path);

            return false;
        }
        if ($node instanceof Directory) {
            $this->error('unlink(%s): Is a directory', $path);

            return false;
        }
        $parent = $fileSystem->find(Path::parent($virtualPath));
        if (!$parent instanceof Directory) {
            $this->error('unlink(%s): No such file or directory', $path);

            return false;
        }
        if (!$parent->isWritableBy($fileSystem->user, $fileSystem->group)) {
            $this->error('unlink(%s): Permission denied', $path);

            return false;
        }
        $parent->removeChild(Path::baseName($virtualPath));

        return true;
    }

    public function rename(string $pathFrom, string $pathTo): bool
    {
        $locatedFrom = $this->locate($pathFrom);
        $locatedTo   = $this->locate($pathTo);
        if ($locatedFrom === null || $locatedTo === null) {
            return false;
        }
        [$fileSystem, $fromPath] = $locatedFrom;
        [$targetFileSystem, $toPath] = $locatedTo;

        if ($fileSystem !== $targetFileSystem) {
            $this->error('rename(%s,%s): Cross-device link', $pathFrom, $pathTo);

            return false;
        }

        $source = $fileSystem->find($fromPath, followFinalLink: false);
        if ($source === null) {
            $this->error('rename(%s,%s): No such file or directory', $pathFrom, $pathTo);

            return false;
        }
        $sourceParent = $fileSystem->find(Path::parent($fromPath));
        $targetParent = $fileSystem->find(Path::parent($toPath));
        if (!$sourceParent instanceof Directory || !$targetParent instanceof Directory) {
            $this->error('rename(%s,%s): No such file or directory', $pathFrom, $pathTo);

            return false;
        }
        if (!$sourceParent->isWritableBy($fileSystem->user, $fileSystem->group)
            || !$targetParent->isWritableBy($fileSystem->user, $fileSystem->group)
        ) {
            $this->error('rename(%s,%s): Permission denied', $pathFrom, $pathTo);

            return false;
        }
        if ($source instanceof Directory && ($toPath === $fromPath || str_starts_with($toPath . '/', $fromPath . '/'))) {
            $this->error('rename(%s,%s): Invalid argument', $pathFrom, $pathTo);

            return false;
        }

        $target = $fileSystem->find($toPath, followFinalLink: false);
        if ($target !== null) {
            if ($source instanceof Directory && !$target instanceof Directory) {
                $this->error('rename(%s,%s): Not a directory', $pathFrom, $pathTo);

                return false;
            }
            if (!$source instanceof Directory && $target instanceof Directory) {
                $this->error('rename(%s,%s): Is a directory', $pathFrom, $pathTo);

                return false;
            }
            if ($target instanceof Directory && !$target->isEmpty) {
                $this->error('rename(%s,%s): Directory not empty', $pathFrom, $pathTo);

                return false;
            }
            $targetParent->removeChild(Path::baseName($toPath));
        }

        $sourceParent->removeChild(Path::baseName($fromPath));
        $targetParent->addChild(Path::baseName($toPath), $source);

        return true;
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        $reportErrors = ($options & STREAM_REPORT_ERRORS) !== 0;
        $recursive    = ($options & STREAM_MKDIR_RECURSIVE) !== 0;

        $located = $this->locate($path);
        if ($located === null) {
            $this->errorIf($reportErrors, 'mkdir(): No such device');

            return false;
        }
        [$fileSystem, $virtualPath] = $located;
        $permissions = $mode & ~umask() & 0o777;

        if ($fileSystem->find($virtualPath, followFinalLink: false) !== null) {
            $this->errorIf($reportErrors, 'mkdir(): File exists');

            return false;
        }

        if (!$recursive) {
            $parent = $fileSystem->find(Path::parent($virtualPath));
            if (!$parent instanceof Directory) {
                $this->errorIf($reportErrors, 'mkdir(): No such file or directory');

                return false;
            }
            if (!$parent->isWritableBy($fileSystem->user, $fileSystem->group)) {
                $this->errorIf($reportErrors, 'mkdir(): Permission denied');

                return false;
            }
            $parent->addChild(Path::baseName($virtualPath), new Directory($permissions, $fileSystem->user, $fileSystem->group));

            return true;
        }

        $current    = $fileSystem->root;
        $walkedPath = '';
        foreach (Path::segments($virtualPath) as $segment) {
            $walkedPath .= '/' . $segment;
            $child = $current->children[$segment] ?? null;
            if ($child === null) {
                if (!$current->isWritableBy($fileSystem->user, $fileSystem->group)) {
                    $this->errorIf($reportErrors, 'mkdir(): Permission denied');

                    return false;
                }
                $child = new Directory($permissions, $fileSystem->user, $fileSystem->group);
                $current->addChild($segment, $child);
            } elseif ($child instanceof SymbolicLink) {
                $child = $fileSystem->find($walkedPath);
                if ($child === null) {
                    $this->errorIf($reportErrors, 'mkdir(): No such file or directory');

                    return false;
                }
            }
            if (!$child instanceof Directory) {
                $this->errorIf($reportErrors, 'mkdir(): Not a directory');

                return false;
            }
            $current = $child;
        }

        return true;
    }

    public function rmdir(string $path, int $options): bool
    {
        $reportErrors = ($options & STREAM_REPORT_ERRORS) !== 0;

        $located = $this->locate($path);
        if ($located === null) {
            return false;
        }
        [$fileSystem, $virtualPath] = $located;

        if ($virtualPath === '/') {
            $this->errorIf($reportErrors, 'rmdir(%s): Permission denied', $path);

            return false;
        }
        $node = $fileSystem->find($virtualPath, followFinalLink: false);
        if ($node === null) {
            $this->errorIf($reportErrors, 'rmdir(%s): No such file or directory', $path);

            return false;
        }
        if (!$node instanceof Directory) {
            $this->errorIf($reportErrors, 'rmdir(%s): Not a directory', $path);

            return false;
        }
        if (!$node->isEmpty) {
            $this->errorIf($reportErrors, 'rmdir(%s): Directory not empty', $path);

            return false;
        }
        $parent = $fileSystem->find(Path::parent($virtualPath));
        if (!$parent instanceof Directory) {
            $this->errorIf($reportErrors, 'rmdir(%s): No such file or directory', $path);

            return false;
        }
        if (!$parent->isWritableBy($fileSystem->user, $fileSystem->group)) {
            $this->errorIf($reportErrors, 'rmdir(%s): Permission denied', $path);

            return false;
        }
        $parent->removeChild(Path::baseName($virtualPath));

        return true;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $located = $this->locate($path);
        if ($located === null) {
            return false;
        }
        [$fileSystem, $virtualPath] = $located;

        $followFinalLink = ($flags & STREAM_URL_STAT_LINK) === 0;
        $node            = $fileSystem->find($virtualPath, $followFinalLink);
        if ($node === null) {
            return false;
        }

        return $this->buildStat($fileSystem, $node);
    }

    /*
     * ---------------------------------------------------------------
     *  Directory handle interface
     * ---------------------------------------------------------------
     */

    public function dir_opendir(string $path, int $options): bool
    {
        $located = $this->locate($path);
        if ($located === null) {
            return false;
        }
        [$fileSystem, $virtualPath] = $located;

        $node = $fileSystem->find($virtualPath);
        if ($node === null) {
            $this->error('opendir(%s): Failed to open directory: No such file or directory', $path);

            return false;
        }
        if (!$node instanceof Directory) {
            $this->error('opendir(%s): Failed to open directory: Not a directory', $path);

            return false;
        }
        if (!$node->isReadableBy($fileSystem->user, $fileSystem->group)) {
            $this->error('opendir(%s): Failed to open directory: Permission denied', $path);

            return false;
        }
        $node->markAccessed();
        $this->directoryEntries  = ['.', '..', ...array_keys($node->children)];
        $this->directoryPosition = 0;

        return true;
    }

    public function dir_readdir(): string|false
    {
        return $this->directoryEntries[$this->directoryPosition++] ?? false;
    }

    public function dir_rewinddir(): bool
    {
        $this->directoryPosition = 0;

        return true;
    }

    public function dir_closedir(): bool
    {
        $this->directoryEntries  = [];
        $this->directoryPosition = 0;

        return true;
    }

    /*
     * ---------------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------------
     */

    /**
     * Resolves a stream URL to its mounted filesystem and virtual path.
     *
     * @return array{FileSystem, string}|null
     */
    private function locate(string $url): ?array
    {
        [$scheme, $virtualPath] = Path::parseUrl($url);
        $fileSystem = FileSystem::get($scheme);
        if ($fileSystem === null) {
            return null;
        }

        return [$fileSystem, $virtualPath];
    }

    /**
     * Creates an empty file for fopen()/touch(), following symbolic links
     * to their target location and enforcing parent directory permissions.
     */
    private function createFileAt(FileSystem $fileSystem, string $virtualPath, ?string &$failureReason = null): ?File
    {
        for ($depth = 0; $depth < self::MAX_LINK_DEPTH; $depth++) {
            $node = $fileSystem->find($virtualPath, followFinalLink: false);
            if (!$node instanceof SymbolicLink) {
                break;
            }
            $virtualPath = Path::resolveTarget(Path::parent($virtualPath), $node->target);
        }

        $parent = $fileSystem->find(Path::parent($virtualPath));
        if (!$parent instanceof Directory) {
            $failureReason = 'No such file or directory';

            return null;
        }
        if (!$parent->isWritableBy($fileSystem->user, $fileSystem->group)) {
            $failureReason = 'Permission denied';

            return null;
        }
        $name = Path::baseName($virtualPath);
        if ($name === '' || isset($parent->children[$name])) {
            $failureReason = 'File exists';

            return null;
        }

        $file = new File(0o666 & ~umask(), $fileSystem->user, $fileSystem->group);
        $parent->addChild($name, $file);

        return $file;
    }

    private function unlockFile(int $ownerId): bool
    {
        $this->file?->unlock($ownerId);

        return true;
    }

    /**
     * @return array<int|string, int>
     */
    private function buildStat(FileSystem $fileSystem, Node $node): array
    {
        $stat = [
            'dev'     => $fileSystem->device,
            'ino'     => $node->inode,
            'mode'    => $node->mode,
            'nlink'   => $node instanceof Directory ? $node->linkCount : 1,
            'uid'     => $node->uid,
            'gid'     => $node->gid,
            'rdev'    => 0,
            'size'    => $node->size,
            'atime'   => $node->accessTime,
            'mtime'   => $node->modificationTime,
            'ctime'   => $node->changeTime,
            'blksize' => 4096,
            'blocks'  => (int) ceil($node->size / 512),
        ];

        return [...array_values($stat), ...$stat];
    }

    private function resolveUserName(mixed $name): ?int
    {
        if (!is_string($name) || !function_exists('posix_getpwnam')) {
            return null;
        }
        $info = posix_getpwnam($name);

        return $info === false ? null : $info['uid'];
    }

    private function resolveGroupName(mixed $name): ?int
    {
        if (!is_string($name) || !function_exists('posix_getgrnam')) {
            return null;
        }
        $info = posix_getgrnam($name);

        return $info === false ? null : $info['gid'];
    }

    private function error(string $format, string ...$arguments): void
    {
        trigger_error(vsprintf($format, $arguments), E_USER_WARNING);
    }

    private function errorIf(bool $reportErrors, string $format, string ...$arguments): void
    {
        if ($reportErrors) {
            $this->error($format, ...$arguments);
        }
    }
}
