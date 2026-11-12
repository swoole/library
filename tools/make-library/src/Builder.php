<?php

namespace SwooleLibrary;

class Builder
{
    protected $checkFileChange;
    protected $librarySrcDir;
    protected $files;
    /** @var string[] Path prefixes that are loaded by the library's autoloader, not compiled into the extension. */
    protected $ignore = [];
    protected $srcPath;
    protected $stripComments = true;
    protected $symbolPrefix;
    protected $outputFile;

    const PHP_TAG = '<?php';
    const NAME_REGX = '#^[a-z0-9_\-]+$#i';

    function __construct($librarySrcDir)
    {
        $this->librarySrcDir = realpath($librarySrcDir);
        if (!$this->librarySrcDir or !is_dir($this->librarySrcDir)) {
            throw new Exception("The LibrarySrcDir is not exists", 1);
        }
        $initFile = $librarySrcDir . '/__init__.php';
        if (!is_file($initFile)) {
            throw new Exception("The __init__.php file [$initFile] not found in LibrarySrcDir", 2);
        }
        $cfg = require $initFile;
        if (empty($cfg['files'])) {
            throw new Exception("The library files must be not empty", 3);
        }
        $this->files = $cfg['files'];
        if (!empty($cfg['ignore'])) {
            if (!is_array($cfg['ignore'])) {
                throw new Exception("The library ignore config must be an array", 6);
            }
            foreach ($cfg['ignore'] as $path) {
                // Normalize to a src-relative path prefix: unify separators, drop empty and "."
                // segments, and resolve "..". e.g. "./core/Grpc/" and "core\Grpc" -> "core/Grpc".
                $segments = explode('/', str_replace('\\', '/', (string) $path));
                $normalized = [];
                foreach ($segments as $segment) {
                    if ($segment === '' || $segment === '.') {
                        continue;
                    }
                    if ($segment === '..') {
                        array_pop($normalized);
                        continue;
                    }
                    $normalized[] = $segment;
                }
                if ($normalized !== []) {
                    $this->ignore[] = implode('/', $normalized);
                }
            }
        }
        if (empty($cfg['name'])) {
            throw new Exception("The library name must be not empty", 4);
        }
        if (!preg_match(self::NAME_REGX, $cfg['name'])) {
            throw new Exception("The library name must be matched with `" . self::NAME_REGX . "`", 5);
        }
        $this->symbolPrefix = str_replace('-', '_', $cfg['name']);
        $this->srcPath = "@{$cfg['name']}/library";
        if (empty($cfg['output'])) {
            throw new Exception("The library output header file must be not empty", 4);
        }
        $this->outputFile = $cfg['output'];
        $this->checkFileChange = !empty($cfg['checkFileChange']);
        $this->stripComments = !empty($cfg['stripComments']);;
    }

    protected function scanFiles(): array
    {
        $files = [];

        $file_spl_objects = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->librarySrcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($file_spl_objects as $full_file_name => $file_spl_object) {
            $files[] = str_replace($this->librarySrcDir . '/', '', $full_file_name);
        }

        return $files;
    }

    /**
     * Whether a scanned src-relative path is covered by an "ignore" prefix. Ignored modules are
     * loaded by the library's own autoloader (e.g. Composer PSR-4) instead of being compiled into
     * the extension header, so they are excluded from the "not loaded" check. A prefix matches the
     * file itself or anything below it as a directory, but never a sibling with a shared prefix:
     * "core/Grpc" matches "core/Grpc/Client.php", not "core/GrpcExtra.php".
     */
    protected function isIgnored(string $file): bool
    {
        $file = str_replace('\\', '/', $file);
        foreach ($this->ignore as $prefix) {
            if ($file === $prefix || str_starts_with($file, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Files present under src/ that are neither declared in "files" nor intentionally ignored.
     * Kept as a separate method so it can be unit-tested without running the full generator.
     */
    public function getUnloadedFiles(): array
    {
        // These two are part of the build machinery, not the library payload.
        $ignore_files = ['vendor_init.php', '__init__.php'];

        $diff_files = array_diff($this->scanFiles(), $this->files);
        foreach ($diff_files as $k => $f) {
            if (in_array($f, $ignore_files, true) || $this->isIgnored($f)) {
                unset($diff_files[$k]);
            }
        }

        return array_values($diff_files);
    }

    protected function removeComments(string $code): string
    {
        $newCode = '';
        $commentTokens = [T_COMMENT];

        if (defined('T_DOC_COMMENT')) {
            $commentTokens[] = T_DOC_COMMENT;
        }

        if (defined('T_ML_COMMENT')) {
            $commentTokens[] = T_ML_COMMENT;
        }

        $tokens = token_get_all($code);
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], $commentTokens)) {
                    continue;
                }
                $token = $token[1];
            }
            $newCode .= $token;
        }

        return $newCode;
    }

    public function make()
    {
        if ($this->checkFileChange) {
            preg_match(
                '/^(\d+)/',
                trim(shell_exec('cd ' . $this->librarySrcDir . ' && git diff --shortstat')),
                $file_change
            );
            $file_change = (int)($file_change[1] ?? 0);
            if ($file_change > 0) {
                swoole_error($file_change . ' file changed in [' . $this->librarySrcDir . ']');
            }
        }

        $commit_id = trim(shell_exec('cd ' . $this->librarySrcDir . ' && git rev-parse HEAD'));
        if (!$commit_id || strlen($commit_id) != 40) {
            swoole_error('Unable to get commit id of library in [' . $this->librarySrcDir . ']');
        }

        $diff_files = $this->getUnloadedFiles();
        if (!empty($diff_files)) {
            swoole_error('Some files are not loaded: ', ...$diff_files);
        }

        foreach ($this->files as $file) {
            if (!file_exists($this->librarySrcDir . '/' . $file)) {
                swoole_error("Unable to find source file [{$file}]");
            }
        }

        $source_str = $eval_str = '';
        foreach ($this->files as $file) {
            $php_file = $this->librarySrcDir . '/' . $file;
            if (strpos(`/usr/bin/env php -n -l {$php_file} 2>&1`, 'No syntax errors detected') === false) {
                swoole_error("Syntax error in file [{$php_file}]");
            } else {
                swoole_ok("Syntax correct in [{$file}]");
            }
            $code = file_get_contents($php_file);
            if ($code === false) {
                swoole_error("Can not read file [{$file}]");
            }
            if (strpos($code, self::PHP_TAG) !== 0) {
                swoole_error("File [{$file}] must start with \"<?php\"");
            }
            if ($this->stripComments) {
                $code = $this->removeComments($code);
            }
            $name = unCamelize(str_replace(['/', '.php'], ['_', ''], $file));
            // keep line breaks to align line numbers
            $code = rtrim(substr($code, strlen(self::PHP_TAG)));
            $code = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', "\\n\"\n\""], $code);
            $code = implode("\n" . space(4), explode("\n", $code));
            $filename = "{$this->srcPath}/{$file}";
            $source_str .= "static const char* {$this->symbolPrefix}_library_source_{$name} =\n" . space(4) . "\"{$code}\\n\";\n\n";
            $eval_str .= space(4) . "_eval({$this->symbolPrefix}_library_source_{$name}, \"{$filename}\");\n";
        }
        $source_str = rtrim($source_str);
        $eval_str = rtrim($eval_str);

        global $argv;
        $generator = basename($argv[0]);
        $content = <<<CODE
/**
 * -----------------------------------------------------------------------
 * Generated by {$generator}, Please DO NOT modify!
  +----------------------------------------------------------------------+
  | Swoole                                                               |
  +----------------------------------------------------------------------+
  | This source file is subject to version 2.0 of the Apache license,    |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.apache.org/licenses/LICENSE-2.0.html                      |
  | If you did not receive a copy of the Apache2.0 license and are unable|
  | to obtain it through the world-wide-web, please send a note to       |
  | license@swoole.com so we can mail you a copy immediately.            |
  +----------------------------------------------------------------------+
 */

/* \$Id: {$commit_id} */

#ifndef SWOOLE_LIBRARY_H
#define SWOOLE_LIBRARY_H

#include "zend_exceptions.h"

#if PHP_VERSION_ID < 80000
typedef zval zend_source_string_t;
#else
typedef zend_string zend_source_string_t;
#endif

#if PHP_VERSION_ID < 80200
#define ZEND_COMPILE_POSITION_DC
#define ZEND_COMPILE_POSITION_RELAY_C
#else
#define ZEND_COMPILE_POSITION_DC , zend_compile_position position
#define ZEND_COMPILE_POSITION_RELAY_C , position
#endif

#if PHP_VERSION_ID < 80000
#define ZEND_STR_CONST
#else
#define ZEND_STR_CONST const
#endif


static zend_op_array *(*old_compile_string)(zend_source_string_t *source_string, ZEND_STR_CONST char *filename ZEND_COMPILE_POSITION_DC);

static inline zend_op_array *_compile_string(zend_source_string_t *source_string, ZEND_STR_CONST char *filename ZEND_COMPILE_POSITION_DC) {
    if (UNEXPECTED(EG(exception))) {
        zend_exception_error(EG(exception), E_ERROR);
        return NULL;
    }
    zend_op_array *opa = old_compile_string(source_string, filename ZEND_COMPILE_POSITION_RELAY_C);
    opa->type = ZEND_USER_FUNCTION;
    return opa;
}

static inline zend_bool _eval(const char *code, const char *filename) {
    if (!old_compile_string) {
        old_compile_string = zend_compile_string;
    }
    // overwrite
    zend_compile_string = _compile_string;
    int ret = (zend_eval_stringl((char *) code, strlen(code), NULL, (char *) filename) == SUCCESS);
    // recover
    zend_compile_string = old_compile_string;
    return ret;
}

#endif

{$source_str}

void php_{$this->symbolPrefix}_load_library(void)
{
{$eval_str}
}

CODE;

        if (file_put_contents($this->outputFile, $content) != strlen($content)) {
            swoole_error('Can not write source codes to ' . $this->outputFile);
        }
        swoole_success("Generated swoole php library successfully!");
    }
}
