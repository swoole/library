<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Grpc\Example;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Address of the demo gRPC/Etcd server. It is example configuration, not part of the library API:
// the original global GRPC_SERVER_DEFAULT_URI was deliberately not carried over.
define(__NAMESPACE__ . '\SERVER_URI', getenv('GRPC_SERVER_DEFAULT_URI') ?: '127.0.0.1:2379');
