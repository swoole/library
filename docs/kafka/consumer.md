# 消费者

## 消费者配置

类名：`Swoole\Kafka\Consumer\ConsumerConfig`

> 支持构造方法传入数组赋值

### 配置参数

| 参数名 | 说明 | 默认值 |
| - | - | - |
| connectTimeout | 连接超时时间（单位：秒，支持小数），为`-1`则不限制 | `-1` |
| sendTimeout | 发送超时时间（单位：秒，支持小数），为`-1`则不限制 | `-1` |
| recvTimeout | 接收超时时间（单位：秒，支持小数），为`-1`则不限制 | `-1` |
| clientId | Kafka 客户端标识，不同的消费者进程请使用不同的设置 | `null` |
| maxWriteAttempts | 最大写入尝试次数 | `3` |
| client | 使用哪个 Kafka 客户端类，默认为`null`时根据场景自动识别 | `null` |
| socket | 使用哪个 Kafka Socket 类，默认为`null`时根据场景自动识别 | `null` |
| brokers | 别名 broker，格式：`'127.0.0.1:9092,127.0.0.1:9093'` 或 `['127.0.0.1:9092','127.0.0.1:9093']` | `null` |
| bootstrapServers | 别名bootstrapServer，引导服务器，如果配置了该值，会自动连接该服务器，并自动更新 brokers。格式：`'127.0.0.1:9092,127.0.0.1:9093'` 或 `['127.0.0.1:9092','127.0.0.1:9093']` | `null` |
| updateBrokers | 是否自动更新 brokers | `true` |
| interval | 未获取消息到消息时，延迟多少秒再次尝试，默认为`0`则不延迟（单位：秒，支持小数） | `0` |
| groupId | 分组 ID | `null` |
| memberId | 用户 ID | `null` |
| groupInstanceId | 分组实例 ID。`null` 是动态成员，不向协调器发送实例 ID。非空字符串是静态成员，重新加入时沿用已分配的 member id。不同进程请使用不同的值 | `null` |
| sessionTimeout | 如果超时后没有收到心跳信号，则协调器会认为该用户死亡。（单位：秒，支持小数） | `60` |
| rebalanceTimeout | 重新平衡组时，协调器等待每个成员重新加入的最长时间（单位：秒，支持小数）。 | `60` |
| topic | 主题名称，支持同时消费多个主题。设置了 `topicPattern` 时以匹配结果为准 | `null` |
| replicaId | 副本 ID | `-1` |
| rackId | 机架编号 | `''` |
| autoCommit | 自动提交 offset | `true` |
| groupRetry | 分组操作，匹配预设的错误码时，自动重试次数 | `5` |
| groupRetrySleep | 分组操作重试延迟，单位：秒 | `1` |
| offsetRetry | 偏移量操作，匹配预设的错误码时，自动重试次数 | `5` |
| groupHeartbeat | 分组心跳时间间隔，单位：秒 | `3` |
| autoCreateTopic | 自动创建主题 | `true` |
| partitionAssignmentStrategy | 消费者分区分配策略，可选：范围分配-`Swoole\Kafka\Consumer\Assignor\RangeAssignor`、轮询分配-`\Swoole\Kafka\Consumer\Assignor\RoundRobinAssignor`、粘性分配-`\Swoole\Kafka\Consumer\Assignor\StickyAssignor` | `Swoole\Kafka\Consumer\Assignor\RangeAssignor` |
| exceptionCallback | 遇到无法在`recv()`协程抛出的异常时，调用此回调。格式：`function(\Exception $e){}` | `null` |
| minBytes | 一次 Fetch 至少凑够这些字节再返回。每个分区的上限使用同一个 `maxBytes`；上限为 0 时 broker 只返回一条记录，单条不够 `minBytes` 时会一直等到 `maxWait` | `1` |
| maxBytes | 整次 Fetch 以及每个分区的最大字节数 | `128 * 1024 * 1024` |
| maxWait | Fetch 请求的 `max_wait_ms`，单位：毫秒。凑不满 `minBytes` 时 broker 最多等待这么久 | `1` |
| autoOffsetReset | 没有已提交偏移，或偏移超出范围时从哪里开始。`earliest` 读取已有记录，`latest` 只读取之后写入的记录 | `earliest` |
| topicPattern | 带分隔符的正则，订阅当前元数据里名称匹配的主题，例如 `#^order-#`。没有匹配时抛出异常 | `''` |
| assignedPartitions | 直接读取给出的分区，不加入消费组。格式 `['topic' => [0, 1]]`。`groupId` 非空时仍用该组读取和提交偏移 | `[]` |
| sasl | SASL身份认证信息。为空则不发送身份认证信息 [详情](#SASL支持) | `[]`|
| ssl | SSL链接相关信息,为空则不使用SSL [详情](#SSL支持) | `null` |

## 异步消费（回调）

**代码示例：**

```php
use Swoole\Kafka\Consumer\ConsumeMessage;
use Swoole\Kafka\Consumer\Consumer;
use Swoole\Kafka\Consumer\ConsumerConfig;

function consume(ConsumeMessage $message)
{
    var_dump($message->getKey() . ':' . $message->getValue());
    // $consumer->ack($message); // autoCommit设为false时，手动提交
}
$config = new ConsumerConfig();
$config->setBroker('127.0.0.1:9092');
$config->setTopic('test'); // 主题名称
$config->setGroupId('testGroup'); // 分组ID
$config->setClientId('test'); // 客户端ID，不同的消费者进程请使用不同的设置
$config->setGroupInstanceId('test'); // 分组实例ID，不同的消费者进程请使用不同的设置
$config->setInterval(0.1);
$consumer = new Consumer($config, 'consume');
$consumer->start();
```

## 同步消费

**代码示例：**

```php
use Swoole\Kafka\Consumer\Consumer;
use Swoole\Kafka\Consumer\ConsumerConfig;

$config = new ConsumerConfig();
$config->setBroker('127.0.0.1:9092');
$config->setTopic('test'); // 主题名称
$config->setGroupId('testGroup'); // 分组ID
$config->setClientId('test_custom'); // 客户端ID，不同的消费者进程请使用不同的设置
$config->setGroupInstanceId('test_custom'); // 分组实例ID，不同的消费者进程请使用不同的设置
$consumer = new Consumer($config);
while(true) {
    $message = $consumer->consume();
    if($message) {
        var_dump($message->getKey() . ':' . $message->getValue());
        $consumer->ack($message); // 手动提交
    }
    sleep(1);
}
```

## SASL支持
### 相关配置
|参数名|说明|默认值|
| - | - | - |
| type | SASL 机制类。见下方 PLAIN、SCRAM-SHA-512、AWS MSK IAM | ''|
| username | 账号。PLAIN 和 SCRAM-SHA-512 使用 | '' |
| password | 密码。PLAIN 和 SCRAM-SHA-512 使用 | '' |
| verify_final_signature | SCRAM-SHA-512 是否校验服务端最后一条签名 | `false` |
| region | AWS MSK IAM 的区域，必填 | '' |
| key | AWS MSK IAM 的 access key。省略时使用 AWS 默认凭证链 | '' |
| secret | AWS MSK IAM 的 secret | '' |
| token | AWS MSK IAM 的 session token | '' |
| expiration | AWS MSK IAM 签名有效期，例如 `+5 minutes` | `+5 minutes` |

**代码示例：**
```php
use Swoole\Kafka\Consumer\Consumer;
use Swoole\Kafka\Consumer\ConsumerConfig;

$config = new ConsumerConfig();
// .... 你的其他配置
$config->setSasl([
    "type"=>\Swoole\Kafka\Sasl\PlainSasl::class,
    "username"=>"admin",
    "password"=>"admin-secret"
]);
$consumer = new Consumer($config);
// ....  你的业务代码
```

### SCRAM-SHA-512

类名：`Swoole\Kafka\Sasl\ScramSha512Sasl`。账号和密码与 PLAIN 相同。`verify_final_signature` 为 `true` 时，客户端用交换过的消息校验服务端签名，不匹配则认证失败。

```php
$config->setSasl([
    'type' => \Swoole\Kafka\Sasl\ScramSha512Sasl::class,
    'username' => 'admin',
    'password' => 'admin-secret',
    'verify_final_signature' => true,
]);
```

### AWS MSK IAM

类名：`Swoole\Kafka\Sasl\AwsMskIamSasl`。需要安装 `aws/aws-sdk-php`。`region` 必填。不填 `key` 和 `secret` 时使用 AWS 默认凭证链。MSK 通常同时打开 SSL。示例：`examples/kafka/consumer_msk.php`。

```php
$config->setSasl([
    'type' => \Swoole\Kafka\Sasl\AwsMskIamSasl::class,
    'region' => 'eu-west-1',
    'expiration' => '+5 minutes',
]);
```

自定义机制实现 `Swoole\Kafka\Sasl\SaslInterface`。构造方法接收客户端和配置，`auth()` 完成握手和认证。`Swoole\Kafka\Sasl\AbstractSasl` 提供握手和 `SaslAuthenticate` 请求。

## SSL支持
类名：`Swoole\Kafka\Config\SslConfig`

> 支持构造方法传入数组赋值
### 配置参数
|参数名|说明|默认值|
| - | - | - |
| open  | 是否开启SSL传输加密 | `false` |
| compression | 是否开启压缩 | `true`  |
| certFile |cert证书存放路径|`''`|
| keyFile |私钥存放路径|`''`|
| passphrase |  cert证书密码 | `''`|
| peerName| 服务器主机名。默认为链接的host| `''`|
| verifyPeer |是否校验远端证书 | `false` |
| verifyPeerName |是否校验远端服务器名称 | `false` |
| verifyDepth | 如果证书链条层次太深，超过了本选项的设定值，则终止验证。 默认不校验层级 | `0`|
| allowSelfSigned | 是否允许自签证书 | `false` | 
| cafile | CA证书路径 | `''`|
| capath  | CA证书目录。会自动扫描该路径下所有pem文件 | `''`|

**代码示例：**

```php
use Swoole\Kafka\Consumer\Consumer;
use Swoole\Kafka\Consumer\ConsumerConfig;
use Swoole\Kafka\Config\SslConfig;

$config = new ConsumerConfig();
// .... 你的其他配置
$sslConfig = new SslConfig();
$sslConfig->setOpen(true);
$sslConfig->setVerifyPeer(true);
$sslConfig->setAllowSelfSigned(true);
$sslConfig->setCafile("/kafka-client/.github/kafka/cert/ca-cert");
$config->setSsl($sslConfig);
$consumer = new Consumer($config);
// ....  你的业务代码
```