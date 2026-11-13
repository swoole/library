# Kafka client

`Swoole\Kafka` is the Kafka client previously published as `longlang/phpkafka`. The classes live in `src/core/Kafka/` and are loaded through Composer. They are not compiled into the Swoole extension.

`producer.php` and `producer_batch.php` send messages. `consumer.php` and `consumer_custom.php` consume them. `docker compose up -d kafka` starts a broker that advertises itself as `kafka:9092`. The app container resolves that name on the compose network. On a host, add `127.0.0.1 kafka` to `/etc/hosts` (port 9092 is published) and then run the scripts below. They use a topic named `test`. Configuration is documented in `docs/kafka/`.

Run a script with the library loaded from this repository:

```bash
php -d swoole.enable_library=Off examples/kafka/producer.php
```

`ConsumerConfig::setAutoOffsetReset()` is `earliest` (the default) or `latest`. `setTopicPattern('#^prefix-#')` subscribes to topic names that match a PCRE. `setAssignedPartitions(['topic' => [0]])` reads those partitions without joining a group. `setGroupInstanceId(null)` is a dynamic member; a non-empty id is static membership and is reused when that consumer rejoins.

SASL mechanisms implement `auth()` themselves. Built-in classes are `PlainSasl`, `ScramSha512Sasl` (`verify_final_signature`) and `AwsMskIamSasl`. The IAM mechanism needs `aws/aws-sdk-php`; `producer_msk.php` and `consumer_msk.php` show the configuration. The consumer and producer pages in `docs/kafka/` list every option.

LZ4 record batches need `exussum12/xxhash`. The sticky assignor needs `chdemko/sorted-collections`. AWS MSK IAM needs `aws/aws-sdk-php`. Record-batch CRC32C uses `hash('crc32c')`.

`generate-message.php` regenerates the protocol classes from `src/core/Kafka/resources/message`. It needs `colinodell/json5`.
