# Consumer

## Consumer configuration

Class `Swoole\Kafka\Consumer\ConsumerConfig`

> You can pass an array to a constructor.

### Configuration key

| Key | Description | Default |
| - | - | - |
| connectTimeout | Connection timeout(unit: second, decimal). `-1` means no limit. | `-1` |
| sendTimeout | Send timeout(unit: second, decimal). `-1` means no limit. | `-1` |
| recvTimeout | Receive timeout (unit: second, decimal). `-1` means no limit. | `-1` |
| clientId | Kafka client ID. Use different settings for different consumers. | `null` |
| maxWriteAttempts | Maximum attempts to write | `3` |
| client | Kafka client used. `null` by default means auto recognition. | `null` |
| socket | Kafka Socket used. `null` by default means auto recognition. | `null` |
| brokers | Alias is broker. Format: `'127.0.0.1:9092,127.0.0.1:9093'` or `['127.0.0.1:9092','127.0.0.1:9093']` | `null` |
| bootstrapServers | Alias bootstrapServer, used to boot the server. If configured, the server will be connected and brokers updated. Format `'127.0.0.1:9092,127.0.0.1:9093'` or `['127.0.0.1:9092','127.0.0.1:9093']`. | `null` |
| updateBrokers | Auto update brokers. | `true` |
| interval | If the message is not received, try again internals. `0` is default and means no intervals(unit: second, decimal). | `0` |
| groupId | Group ID | `null` |
| memberId | Member ID | `null` |
| groupInstanceId | Group instance ID. `null` is a dynamic member and sends no instance id. A non-empty string is static membership: a rejoin reuses the member id the broker already assigned. Use a different value for each process. | `null` |
| sessionTimeout | If no heartbeat sent out after the timeout, the group coordinator will consider it dead. (unit: second, decimal) | `60` |
| rebalanceTimeout | The maximum time the coordinator waits for consumers to join. (unit: second, decimal) | `60` |
| topic | Topic name. Several topics can be consumed together. `topicPattern` replaces this list when it is set. | `null` |
| replicaId | Replica ID | `-1` |
| rackId | Rack ID | `''` |
| autoCommit | Auto commit offset | `true` |
| groupRetry | Group retries allowed if matching an error code. | `5` |
| groupRetrySleep | Group retry sleep time. (unit: second) | `1` |
| offsetRetry | Offset retries if matching an error code. | `5` |
| groupHeartbeat | Group heartbeat intervals. (unit: second) | `3` |
| autoCreateTopic | Auto create topic. | `true` |
| partitionAssignmentStrategy | Consumer partition assignment strategy. Optional: Range-`Swoole\Kafka\Consumer\Assignor\RangeAssignor`, RoundRobin-`\Swoole\Kafka\Consumer\Assignor\RoundRobinAssignor`, Sticky-`\Swoole\Kafka\Consumer\Assignor\StickyAssignor`. |
| exceptionCallback | This callback is called when an exception that cannot be thrown by the `recv()` coroutine is encountered. Format: `function(\Exception $e){}` | `null` |
| minBytes | A fetch returns once the broker has at least this many bytes. Each partition uses the same `maxBytes`. A per-partition limit of 0 makes the broker return one record, so a `minBytes` larger than that record waits until `maxWait`. | `1` |
| maxBytes | Maximum bytes for the whole fetch and for each partition. | `128 * 1024 * 1024` |
| maxWait | Fetch `max_wait_ms`, in milliseconds. How long the broker waits when `minBytes` is not reached. | `1` |
| autoOffsetReset | Where to start when the group has no committed offset, or the offset is out of range. `earliest` reads records already in the log. `latest` reads only records produced after the consumer starts. | `earliest` |
| topicPattern | A PCRE with delimiters. Subscribes to topic names in the current metadata that match, for example `#^order-#`. Throws when the pattern is invalid or nothing matches. | `''` |
| assignedPartitions | Read the given partitions without joining a group. Format: `['topic' => [0, 1]]`. A non-empty `groupId` still fetches and commits offsets for that group. | `[]` |
| sasl |  SASL authentication Info. If the field is null, it will not authenticate with SASL [detail](#SASL-Support) | `[]`|
| ssl |  SSL Connect Info. If the field is null, it will not use SSL [detail](#SSL-Support) | `null`|

## Asynchronous (callback)

**Example**

```php
use Swoole\Kafka\Consumer\ConsumeMessage;
use Swoole\Kafka\Consumer\Consumer;
use Swoole\Kafka\Consumer\ConsumerConfig;

function consume(ConsumeMessage $message)
{
    var_dump($message->getKey() . ':' . $message->getValue());
    // $consumer->ack($message); // If autoCommit is set as false, commit manually.
}
$config = new ConsumerConfig();
$config->setBroker('127.0.0.1:9092');
$config->setTopic('test'); // topic
$config->setGroupId('testGroup'); // group ID
$config->setClientId('test'); // client ID. Use different settings for different consumers.
$config->setGroupInstanceId('test'); // group instance ID. Use different settings for different consumers.
$config->setInterval(0.1);
$consumer = new Consumer($config, 'consume');
$consumer->start();
```

## Synchronous

**Example**

```php
use Swoole\Kafka\Consumer\Consumer;
use Swoole\Kafka\Consumer\ConsumerConfig;

$config = new ConsumerConfig();
$config->setBroker('127.0.0.1:9092');
$config->setTopic('test'); // topic
$config->setGroupId('testGroup'); // group ID
$config->setClientId('test_custom'); // client ID. Use different settings for different consumers.
$config->setGroupInstanceId('test_custom'); // group instance ID. Use different settings for different consumers.
$consumer = new Consumer($config);
while(true) {
    $message = $consumer->consume();
    if($message) {
        var_dump($message->getKey() . ':' . $message->getValue());
        $consumer->ack($message); // commit manually
    }
    sleep(1);
}
```

## SASL Support
### Configuration
| Key | Description | Default |
| - | - | - |
| type | SASL mechanism class. See PLAIN, SCRAM-SHA-512 and AWS MSK IAM below. | ''|
| username | Username. Used by PLAIN and SCRAM-SHA-512. | '' |
| password | Password. Used by PLAIN and SCRAM-SHA-512. | '' |
| verify_final_signature | SCRAM-SHA-512: check the server's final signature. | `false` |
| region | AWS MSK IAM region. Required. | '' |
| key | AWS MSK IAM access key. Omitted values use the AWS default credential chain. | '' |
| secret | AWS MSK IAM secret. | '' |
| token | AWS MSK IAM session token. | '' |
| expiration | AWS MSK IAM signature lifetime, for example `+5 minutes`. | `+5 minutes` |

**Example**
```php
use Swoole\Kafka\Consumer\Consumer;
use Swoole\Kafka\Consumer\ConsumerConfig;

$config = new ConsumerConfig();
// .... Your Othor Config
$config->setSasl([
    "type"=>\Swoole\Kafka\Sasl\PlainSasl::class,
    "username"=>"admin",
    "password"=>"admin-secret"
]);
$consumer = new Consumer($config);
// ....  Your Business Code
```

### SCRAM-SHA-512

Class `Swoole\Kafka\Sasl\ScramSha512Sasl`. Username and password are the same fields as PLAIN. With `verify_final_signature` set to `true`, the client checks the server signature against the messages just exchanged and fails authentication when it does not match.

```php
$config->setSasl([
    'type' => \Swoole\Kafka\Sasl\ScramSha512Sasl::class,
    'username' => 'admin',
    'password' => 'admin-secret',
    'verify_final_signature' => true,
]);
```

### AWS MSK IAM

Class `Swoole\Kafka\Sasl\AwsMskIamSasl`. Install `aws/aws-sdk-php`. `region` is required. Leave `key` and `secret` empty to use the AWS default credential chain. MSK is normally used together with SSL. See `examples/kafka/consumer_msk.php`.

```php
$config->setSasl([
    'type' => \Swoole\Kafka\Sasl\AwsMskIamSasl::class,
    'region' => 'eu-west-1',
    'expiration' => '+5 minutes',
]);
```

A custom mechanism implements `Swoole\Kafka\Sasl\SaslInterface`. The constructor receives the client and the config, and `auth()` performs the handshake and authentication. `Swoole\Kafka\Sasl\AbstractSasl` sends the handshake and the `SaslAuthenticate` request.

## SSL Support
Class `Swoole\Kafka\Config\SslConfig`

> You can pass an array to a constructor.

### Configuration keys
| Key | Description | Default |
| - | - | - |
| open  | Enable SSL  | `false` |
| compression | TLS compression. | `true`  |
| certFile |Path to local certificate file on filesystem. |`''`|
| keyFile |Path to local private key file on filesystem|`''`|
| passphrase |  Passphrase with which your ``certFile`` file was encoded. | `''`|
| peerName |  Peer name to be used. If this value is not set, then the name is remote Host | `''`|
| verifyPeer |Require verification of SSL certificate used. | `false` |
| verifyPeerName |Require verification of peer name.| `false` |
| verifyDepth | Abort if the certificate chain is too deep. | `0`|
| allowSelfSigned | Allow self-signed certificates. | `false` | 
| cafile | Location of Certificate Authority file on local filesystem which should be used  | `''`|
| capath  | If cafile is not specified or if the certificate is not found there, the directory pointed to by capath is searched for a suitable certificate. capath must be a correctly hashed certificate directory. | `''`|

**Example**

```php
use Swoole\Kafka\Consumer\Consumer;
use Swoole\Kafka\Consumer\ConsumerConfig;
use Swoole\Kafka\Config\SslConfig;

$config = new ConsumerConfig();
// .... Your Othor Config
$sslConfig = new SslConfig();
$sslConfig->setOpen(true);
$sslConfig->setVerifyPeer(true);
$sslConfig->setAllowSelfSigned(true);
$sslConfig->setCafile("/kafka-client/.github/kafka/cert/ca-cert");
$config->setSsl($sslConfig);
$consumer = new Consumer($config);
// ....  Your Business Code
```