<?php

declare(strict_types=1);

/**
 * Child process for B01_Mmap cross-process measurement.
 *
 *   php _b01_child_loader.php json data/lookup.json
 *   php _b01_child_loader.php mmap data/lookup.bin
 *
 * Loads the table from scratch (parent's page cache is hot) and prints
 * self-measured load+first-lookup time in nanoseconds.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpLlamaBench\Stats;

$mode = $argv[1] ?? 'json';
$path = $argv[2] ?? '';
if (!is_file($path)) {
    fwrite(STDERR, "child: path not found: $path\n");
    exit(2);
}

if ($mode === 'json') {
    $t0 = hrtime(true);
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data[0])) {
        fwrite(STDERR, "child json: decode failed\n");
        exit(3);
    }
    $first = $data[0];
    $t1 = hrtime(true);
    $rss  = Stats::processRss();
    fwrite(STDOUT, ($t1 - $t0) . ' ' . $rss . "\n");
    exit(0);
}

if ($mode === 'mmap') {
    $libc = match (PHP_OS_FAMILY) {
        'Darwin' => 'libc.dylib',
        'Linux'  => 'libc.so.6',
        default  => null,
    };
    if ($libc === null) {
        fwrite(STDERR, "child mmap: unsupported OS\n");
        exit(4);
    }
    $ffi = FFI::cdef(<<<'CDEF'
        void *mmap(void *addr, size_t length, int prot, int flags, int fd, long offset);
        int munmap(void *addr, size_t length);
        int open(const char *pathname, int flags);
        int close(int fd);
        CDEF, $libc);

    $size = (int) filesize($path);
    $t0 = hrtime(true);
    $fd = $ffi->open($path, 0);
    if ($fd < 0) {
        fwrite(STDERR, "child mmap: open failed\n");
        exit(5);
    }
    $ptr = $ffi->mmap(null, $size, 1, 2, $fd, 0);
    $ffi->close($fd);
    $u32 = $ffi->cast('uint32_t*', $ptr);
    if ($u32 === null) {
        fwrite(STDERR, "child mmap: cast null\n");
        exit(7);
    }
    $first = $u32[1];
    $t1 = hrtime(true);
    $rss  = Stats::processRss();
    $ffi->munmap($ptr, $size);
    if ($first < 0) {
        fwrite(STDERR, "child mmap: first vanished\n");
        exit(6);
    }
    fwrite(STDOUT, ($t1 - $t0) . ' ' . $rss . "\n");
    exit(0);
}

fwrite(STDERR, "child: unknown mode $mode\n");
exit(1);
