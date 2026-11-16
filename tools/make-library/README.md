# make-library

Convert `PHP` code to a `C/C++` header file (`php_swoole_library.h`).

This tool is **vendored inside `swoole/library`** under `tools/make-library/`. The Swoole build
(`tools/build-library.php` at the repository root) calls it directly, so the embedded PHP library
is always generated with a version of the generator that matches this repo's `src/embedded_manifest.php`.
There is no separate `swoole/make-library` package to install or release.

## Edit `embedded_manifest.php`

Edit the `embedded_manifest.php` file in the source code directory, the `make-library` script will read this file to configuration.

```php
# file: embedded_manifest.php
<?php
return [
    'name' => 'swoole',  // Library name
    'checkFileChange' => false, // Reject staged, unstaged and untracked changes in the git repository
    'output' => '/your/c_header_file_path', // Generated C/C++ header file path
    'stripComments' => true, // Whether to remove comments (false keeps them)
    /* Notice: Sort by dependency */
    'files' => [
        # <basic> #
        'constants.php',
        # <std> #
        'std/exec.php',
        # <core> #
        'core/Constant.php',
        # ... more files #
    ],
    // Optional: modules that must NOT be compiled into the extension. These are loaded by the
    // library's own autoloader (e.g. Composer PSR-4) at runtime. Any file under these paths is
    // skipped by the "Some files are not loaded" check; paths are src-relative prefixes, so
    // 'core/Grpc' covers the whole directory. Files listed in 'files' above always take priority.
    'ignore' => [
        'core/Grpc',
    ],
];
```

### `ignore` (optional)

By default `make-library` requires every file under `src/` (except `embedded_manifest.php` and
`vendor_init.php`) to be listed in `files`; otherwise the build fails with
`Some files are not loaded: ...`. This catches files accidentally left out of the compiled
header.

Some libraries ship Composer-only modules that are intentionally loaded through an autoloader
instead of being embedded in the extension. List their paths in `ignore` to exclude them from
that check. `ignore` entries are src-relative path prefixes and may point to a directory or a
single file. A prefix matches the path itself and anything nested below it (directory boundary),
not a sibling that merely shares the prefix — `core/Grpc` matches `core/Grpc/Client.php` but not
`core/GrpcExtra.php`. A path explicitly listed in `files` is still embedded; `ignore` only
exempts unlisted paths from the completeness check.

## Make header file

From the `swoole/library` repository root:
```
php tools/build-library.php           # builds <swoole-src>/ext-src/php_swoole_library.h
# or call the generator directly:
php tools/make-library/bin/make-library.php ./src
```

The first parameter is the source code path, there must exist `embedded_manifest.php` in this directory.
Both entry points escape C++14 trigraphs in the generated header, preserving the original PHP
bytes. Source paths are passed as literal command arguments, including spaces and quotes.
Generation fails if two source paths produce the same C symbol, or a path produces an invalid
identifier. With `checkFileChange` enabled, the repository must be clean before a header can
be stamped with its commit id; development mode (`php tools/build-library.php dev`) skips
this check. For local development inside this tool directory, `composer install` + `composer test`
still work; the generator itself needs no Composer dependencies or native compilation.

## Loader library in php extension
```c
#include "php_{$libaryName}_library.h"

// execute in RINIT function
php_{$libaryName}_load_library();
```

## Run tests
```
composer test
# or directly
php tests/run.php
```
