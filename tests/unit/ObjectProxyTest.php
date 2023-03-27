<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole;

use Swoole\Tests\DatabaseTestCase;

/**
 * Class ObjectProxyTest
 *
 * @internal
 * @coversNothing
 */
class ObjectProxyTest extends DatabaseTestCase
{
    public function tearDown(): void
    {
        parent::tearDown();
        $this->addToAssertionCount(2); // Add those in method $this->testClone().
    }

    /**
     * @covers \Swoole\Database\ObjectProxy::__clone()
     */
    public function testClone()
    {
        Coroutine\run(function () {
            $dbs = [
                $this->getPdoPool()->get(),
                $this->getMysqliPool()->get(),
            ];

            foreach ($dbs as $db) {
                try {
                    var_dump(clone $db);
                } catch (\Error $e) {
                    if ($e->getMessage() != 'Trying to clone an uncloneable database proxy object') {
                        throw $e;
                    }
                }
            }
        });
    }
}
