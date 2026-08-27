<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Node;

/**
 * File type bits of the st_mode stat field, as defined by POSIX.
 */
enum NodeType: int
{
    case File         = 0o100000;
    case Directory    = 0o040000;
    case SymbolicLink = 0o120000;
}
