<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine;
use Swoole\Tests\DatabaseTestCase;

/**
 * @internal
 */
#[CoversClass(PDOStatementProxy::class)]
class PDOStatementProxyTest extends DatabaseTestCase
{
    public function testRun()
    {
        Coroutine\run(function () {
            self::assertFalse(
                self::getPdoMysqlPool()->get()->query("SHOW TABLES like 'NON_EXISTING_TABLE_NAME'")->fetch(\PDO::FETCH_ASSOC),
                'FALSE is returned if no results found.'
            );
        });
    }

    public static function dataSetFetchMode(): array
    {
        return [
            [
                [
                    ['col1' => '1', 'col2' => '2'],
                    ['col1' => '3', 'col2' => '4'],
                    ['col1' => '5', 'col2' => '6'],
                ],
                [\PDO::FETCH_ASSOC],
                'Test the  fetch mode "PDO::FETCH_ASSOC"',
            ],
            [
                [
                    '2',
                    '4',
                    '6',
                ],
                [\PDO::FETCH_COLUMN, 1],
                'Test the  fetch mode "PDO::FETCH_COLUMN"',
            ],
            [
                [
                    (object) ['col1' => '1', 'col2' => '2'],
                    (object) ['col1' => '3', 'col2' => '4'],
                    (object) ['col1' => '5', 'col2' => '6'],
                ],
                [\PDO::FETCH_CLASS, \stdClass::class],
                'Test the  fetch mode "PDO::FETCH_CLASS"',
            ],
        ];
    }

    #[DataProvider('dataSetFetchMode')]
    public function testSetFetchMode(array $expected, array $args, string $message)
    {
        Coroutine\run(function () use ($expected, $args, $message) {
            $stmt = self::getPdoMysqlPool()->get()->query(
                'SELECT
                 *
                 FROM (
                     SELECT 1 as col1, 2 as col2
                     UNION SELECT 3, 4
                     UNION SELECT 5, 6
                 ) `table1`'
            );
            $stmt->setFetchMode(...$args);
            self::assertEquals($expected, $stmt->fetchAll(), $message);
        });
    }

    public function testBindParam()
    {
        Coroutine\run(function () {
            $stmt  = self::getPdoMysqlPool()->get()->prepare('SHOW TABLES like ?');
            $table = 'NON_EXISTING_TABLE_NAME';
            $stmt->bindParam(1, $table, \PDO::PARAM_STR);
            $stmt->execute();
            self::assertIsArray($stmt->fetchAll());
        });
    }
}
