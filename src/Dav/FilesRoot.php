<?php
// src/Dav/FilesRoot.php

namespace RoundDAV\Dav;

use Sabre\DAV\FS\Directory;

/**
 * FilesRoot exposes a filesystem directory as a top-level
 * WebDAV collection named "files".
 *
 * Current behavior: shared space for all authenticated users.
 * (Per-user isolation can be added later by implementing a
 * custom backend that maps principals to subdirectories.)
 */
class FilesRoot extends Directory
{
    public function getName(): string
    {
        // Ensure the collection name is always "files"
        return 'files';
    }
}
