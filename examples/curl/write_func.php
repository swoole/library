<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Runtime;

require dirname(__DIR__) . '/bootstrap.php';

Runtime::setHookFlags(SWOOLE_HOOK_CURL);

Co\run(function () {
    $ch       = curl_init('https://httpbin.org/');
    $response = '';
    curl_setopt($ch, CURLOPT_WRITEFUNCTION,
        function ($curl, $data) use (&$response) {
            $response .= $data;
            return strlen($data);
        }
    );
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error: ' . curl_error($ch);
    } else {
        echo 'Response length: ' . strlen($response) . " bytes\n";
    }
    curl_close($ch);
});
