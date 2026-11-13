# Producer

## Producer configuration

Class `Swoole\Kafka\Producer\ProducerConfig`

> You can pass an array to a constructor.

### Configuration keys

| Key | Description | Default |
| - | - | - |
| connectTimeout | Connection timeout(unit: second, decimal). `-1` means no limit. | `-1` |
| sendTimeout | Connection timeout(unit: second, decimal). `-1` means no limit. | `-1` |
| recvTimeout | Connection timeout(unit: second, decimal).`-1` means no limit. | `-1` |
| clientId | Kafka client ID | `null` |
| maxWriteAttempts | Maximum attempts to write | `3` |
| client | Kafka client used. `null` by default means auto recognition. | `null` |
| socket | Kafka Socket used. `null` by default means auto recognition. | `null` |
| brokers | Configure brokers. If configure it manually, set `updateBrokers` to `true`. Format: `'127.0.0.1:9092,127.0.0.1:9093'` or `['127.0.0.1:9092','127.0.0.1:9093']` | `null` |
| bootstrapServers | Alias bootstrapServer, used to boot the server. If configured, the server will be connected and brokers updated. Format: `'127.0.0.1:9092,127.0.0.1:9093'` or `['127.0.0.1:9092','127.0.0.1:9093']` | `null` |
| updateBrokers | Auto update brokers | `true` |
| acks | The producer acknowledges the leader before responding. 0 means not confirmed, 1 means the leader confirmed, -1 means ISR. | `0` |
| producerId | producer ID | `-1` |
| producerEpoch | producer Epoch | `-1` |
| partitionLeaderEpoch | partition Leader Epoch | `-1` |
| autoCreateTopic | auto create topic | `true` |
| exceptionCallback | This callback is called when an exception that cannot be thrown by the `recv()` coroutine is encountered. Format: `function(\Exception $e){}` | `null` |
| partitioner | Partitioning strategy |  Default: `\Swoole\Kafka\Producer\Partitioner\DefaultPartitioner` |
| produceRetry | Produce message retries allowed if matching an error code. | `3` |
| produceRetrySleep | Produce message retry sleep time. (unit: second) | `0.1` |
| sasl |  SASL authentication Info. If the field is null, it will not authenticate with SASL [detail](#SASL-Support) | `[]`|
| ssl |  SSL Connect Info. If the field is null, it will not use SSL [detail](#SSL-Support) | `null` |

**Default partitioning strategy：**

If partition !== null, then use partition

If partition === null && key !== null, then use crc32(key) % partitions to select partition

If partition === null && key === null, then use Round Robin to select partition

## Send a single message

**Example**

```php
use Swoole\Kafka\Producer\Producer;
use Swoole\Kafka\Producer\ProducerConfig;
use Swoole\Kafka\Protocol\RecordBatch\RecordHeader;

$config = new ProducerConfig();
$config->setBootstrapServer('127.0.0.1:9092');
$config->setUpdateBrokers(true);
$config->setAcks(-1);
$producer = new Producer($config);
$topic = 'test';
$value = (string) microtime(true);
$key = uniqid('', true);
$producer->send('test', $value, $key);

// set headers
// key-value or use RecordHeader
$headers = [
    'key1' => 'value1',
    (new RecordHeader())->setHeaderKey('key2')->setValue('value2'),
];
$producer->send('test', $value, $key, $headers);
```

## Send batch messages

**Example**

```php
use Swoole\Kafka\Producer\ProduceMessage;
use Swoole\Kafka\Producer\Producer;
use Swoole\Kafka\Producer\ProducerConfig;

$config = new ProducerConfig();
$config->setBootstrapServer('127.0.0.1:9092');
$config->setUpdateBrokers(true);
$config->setAcks(-1);
$producer = new Producer($config);
$topic = 'test';
$partition0 = 0;
$partition1 = 1;
$producer->sendBatch([
    new ProduceMessage($topic, 'v1', 'k1', $partition0),
    new ProduceMessage($topic, 'v2', 'k2', $partition1),
]);
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
use Swoole\Kafka\Producer\ProducerConfig;
use Swoole\Kafka\Producer\Producer;

$config = new ProducerConfig();
// .... Your Other Config
$config->setSasl([
    "type"=>\Swoole\Kafka\Sasl\PlainSasl::class,
    "username"=>"admin",
    "password"=>"admin-secret"
]);
$producer = new Producer($config);
// ....  Your Business Code
```

### SCRAM-SHA-512

Class `Swoole\Kafka\Sasl\ScramSha512Sasl`. Username and password use the same fields as PLAIN. Set `verify_final_signature` to `true` to check the server's final signature.

```php
$config->setSasl([
    'type' => \Swoole\Kafka\Sasl\ScramSha512Sasl::class,
    'username' => 'admin',
    'password' => 'admin-secret',
]);
```

### AWS MSK IAM

Class `Swoole\Kafka\Sasl\AwsMskIamSasl`. Install `aws/aws-sdk-php`. `region` is required. See `examples/kafka/producer_msk.php`.

```php
$config->setSasl([
    'type' => \Swoole\Kafka\Sasl\AwsMskIamSasl::class,
    'region' => 'eu-west-1',
]);
```

A custom mechanism implements `Swoole\Kafka\Sasl\SaslInterface` and performs the handshake and authentication in `auth()`. `Swoole\Kafka\Sasl\AbstractSasl` sends those two requests.

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
use Swoole\Kafka\Producer\ProducerConfig;
use Swoole\Kafka\Producer\Producer;
use Swoole\Kafka\Config\SslConfig;

$config = new ProducerConfig();
// .... Your Othor Config
$sslConfig = new SslConfig();
$sslConfig->setOpen(true);
$sslConfig->setVerifyPeer(true);
$sslConfig->setAllowSelfSigned(true);
$sslConfig->setCafile("/kafka-client/.github/kafka/cert/ca-cert");
$config->setSsl($sslConfig);
$producer = new Producer($config);
// ....  Your Business Code
```
