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

use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers ::swoole_table
 */
class TableFunctionTest extends TestCase
{
    public function testFieldTypes(): void
    {
        $table = swoole_table(64, ' count : int, ratio:f, name : string : 16, short:s:8, number:i, float:float ');
        try {
            self::assertTrue($table->set('row', ['count' => 2, 'ratio' => 1.5, 'name' => 'hello', 'short' => 'world', 'number' => 3, 'float' => 2.5]));
            self::assertSame(['count' => 2, 'ratio' => 1.5, 'name' => 'hello', 'short' => 'world', 'number' => 3, 'float' => 2.5], $table->get('row'));
        } finally {
            $table->destroy();
        }
    }

    /**
     * @dataProvider invalidFields
     */
    public function testInvalidFieldDefinitions(string $fields): void
    {
        $this->expectException(\RuntimeException::class);
        swoole_table(64, $fields);
    }

    public static function invalidFields(): iterable
    {
        foreach (['', 'name', ':int', 'name:', 'name:other', 'name:s', 'name:s:0', 'name:s:-1', 'name:s:1.5', 'name:s:8abc', 'name:s:999999999999999999999999', 'name:i:8', 'name:s:8:extra', 'name:i,'] as $fields) {
            yield $fields => [$fields];
        }
    }
}
