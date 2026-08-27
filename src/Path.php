<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem;

/**
 * Utility for parsing "scheme://path" URLs and normalizing virtual paths.
 *
 * All virtual paths are absolute, use forward slashes and never contain
 * empty, "." or ".." segments after normalization. Attempts to traverse
 * above the root are clamped to the root, exactly like on a real POSIX
 * filesystem.
 *
 * @internal
 */
final class Path
{
    private function __construct()
    {
    }

    /**
     * Splits a "scheme://path" URL into its scheme and normalized path.
     *
     * @return array{string, string} The scheme and the normalized absolute path
     */
    public static function parseUrl(string $url): array
    {
        $separatorPosition = strpos($url, '://');
        if ($separatorPosition === false) {
            return ['', self::normalize($url)];
        }

        $scheme = substr($url, 0, $separatorPosition);
        $path   = substr($url, $separatorPosition + 3);

        return [$scheme, self::normalize('/' . $path)];
    }

    /**
     * Normalizes a path: collapses "//", resolves "." and "..", clamps at root.
     */
    public static function normalize(string $path): string
    {
        return '/' . implode('/', self::segments($path));
    }

    /**
     * Returns the list of path segments after normalization.
     *
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        $segments = [];
        foreach (explode('/', strtr($path, '\\', '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * Returns the parent path of the given path ("/" for top-level entries).
     */
    public static function parent(string $path): string
    {
        $segments = self::segments($path);
        array_pop($segments);

        return '/' . implode('/', $segments);
    }

    /**
     * Returns the last path segment ("" for the root path).
     */
    public static function baseName(string $path): string
    {
        $segments = self::segments($path);
        $last     = array_pop($segments);

        return $last ?? '';
    }

    /**
     * Resolves a symbolic link target relative to the directory containing the link.
     */
    public static function resolveTarget(string $linkParentPath, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return self::normalize($target);
        }

        return self::normalize($linkParentPath . '/' . $target);
    }
}
