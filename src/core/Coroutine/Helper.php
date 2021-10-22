<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Coroutine;

use Swoole\NameService\BaseObject;

class Helper
{
    public static function nameResolve($name, $resolver_list)
    {
        foreach ($resolver_list as $resolver) {
            if (!($resolver instanceof BaseObject)) {
                continue;
            }
            if ($resolver->hasFilter() and ($resolver->getFilter())($name) !== true) {
                continue;
            }
            return $resolver->resolve($name);
        }
    }
}
