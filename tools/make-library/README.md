# make-library

Convert `PHP` code to a `C/C++` header file (`php_swoole_library.h`).

This tool is **vendored inside `swoole/library`** under `tools/make-library/`. The Swoole build
(`tools/build-library.php` at the repository root) calls it directly, so the embedded PHP library
is always generated with a version of the generator that matches this repo's `src/__init__.php`.
There is no separate `swoole/make-library` package to install or release.

## Edit `__init__.php`

Edit the `__init__.php` file in the source code directory, the `make-library` script will read this file to configuration.

```php
# file: __init__.php
<?php
return [
    'name' => 'swoole',  // Library name
    'checkFileChange' => false, // Check if there are uncommitted changes in the git repository
    'output' => '/your/c_header_file_path', // Generated C/C++ header file path
    'stripComments' => true, // Whether to keep comments
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

By default `make-library` requires every file under `src/` (except `__init__.php` and
`vendor_init.php`) to be listed in `files`; otherwise the build fails with
`Some files are not loaded: ...`. This catches files accidentally left out of the compiled
header.

Some libraries ship Composer-only modules that are intentionally loaded through an autoloader
instead of being embedded in the extension. List their paths in `ignore` to exclude them from
that check. `ignore` entries are src-relative path prefixes and may point to a directory or a
single file. A prefix matches the path itself and anything nested below it (directory boundary),
not a sibling that merely shares the prefix — `core/Grpc` matches `core/Grpc/Client.php` but not
`core/GrpcExtra.php`. Ignored files are never written to the generated header.

## Make header file

From the `swoole/library` repository root:
```
php tools/build-library.php           # builds <swoole-src>/ext-src/php_swoole_library.h
# or call the generator directly:
php tools/make-library/bin/make-library.php ./src
```

The first parameter is the source code path, there must exist `__init__.php` in this directory.
`tools/build-library.php` also applies the C++14 trigraph escaping that the extension build
needs. For local development inside this tool directory, `composer install` + `composer test`
still work; the generator itself needs no Composer dependencies.

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
