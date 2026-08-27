<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Exception;

/**
 * Thrown when a filesystem cannot be mounted or unmounted.
 */
final class RegistrationException extends \RuntimeException implements VfsException
{
}
