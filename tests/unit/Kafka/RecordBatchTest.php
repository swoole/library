<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Kafka;

use Swoole\Kafka\Protocol\RecordBatch\Record;
use Swoole\Kafka\Protocol\RecordBatch\RecordBatch;
use Swoole\Kafka\Protocol\Type\Int32;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Kafka\Protocol\RecordBatch\RecordBatch
 */
class RecordBatchTest extends TestCase
{
    public function testOneFetchPayloadWithTwoBatchesReturnsBothRecords(): void
    {
        $first  = (new RecordBatch())->setRecords([(new Record())->setValue('one')]);
        $second = (new RecordBatch())->setRecords([(new Record())->setValue('two')]);
        $body   = substr($first->pack(), 4) . substr($second->pack(), 4);

        $decoded = new RecordBatch();
        $size    = 0;
        $decoded->unpack(Int32::pack(\strlen($body)) . $body, $size);

        $values = [];
        foreach ($decoded->getRecords() as $record) {
            $values[] = $record->getValue();
        }
        $this->assertSame(['one', 'two'], $values);
    }
}
