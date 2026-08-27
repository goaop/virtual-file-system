<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Exception;

/**
 * Thrown when a filesystem API operation cannot be completed,
 * e.g. creating a file over an existing directory.
 */
final class OperationException extends \RuntimeException implements VfsException
{
}
