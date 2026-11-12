<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Coroutine\System;

function swoole_exec(string $command, mixed &$output = null, mixed &$returnVar = null): string|false
{
    $result = System::exec($command);
    if ($result) {
        $outputList = explode(PHP_EOL, $result['output']);
        foreach ($outputList as &$value) {
            // explode() already removed PHP_EOL. A trailing CR remains when the command used CRLF
            // and PHP_EOL is LF. Spaces at the end of a line are part of the output, as with exec().
            if (str_ends_with($value, "\r")) {
                $value = substr($value, 0, -1);
            }
        }
        if (($endLine = end($outputList)) === '') {
            array_pop($outputList);
            $endLine = end($outputList);
        }
        /* Like exec(), append when the variable already holds an array; anything else is replaced. */
        if (is_array($output) && $output) {
            $output = array_merge($output, $outputList);
        } else {
            $output = $outputList;
        }
        $returnVar = $result['code'];
        /* Like exec(), report a command that succeeds without output as an empty string, not as a failure. */
        return $endLine === false ? '' : $endLine;
    }
    return false;
}

function swoole_shell_exec(string $cmd): string|false|null
{
    $result = System::exec($cmd);
    if ($result === false) {
        return false;
    }
    if ($result['output'] !== '') {
        return $result['output'];
    }
    return null;
}
