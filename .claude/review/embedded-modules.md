# 内嵌模块分批 Review 记录

本文件记录对**编译进 Swoole 扩展**的代码所做的分批审查，供开发过程使用，不面向库用户。
`.claude/` 已在 `.gitattributes` 中 `export-ignore`，不会进入发布包；与 `docs/en`、`docs/cn` 的用户指南分离。

## 范围

`src/__init__.php` 的 `files` 列表（59 个文件，约 1.2 万行）。
排除 `ignore` 中的 Composer-only 模块：`core/Grpc`、`core/Debugger`、`core/Proxy`、`core/Kafka`、
`core/FastCGI.php`、`core/FastCGI`、`core/Coroutine/FastCGI`。

## 批次与进度

| 批次 | 范围 | 状态 |
| --- | --- | --- |
| 1 | 基础层：`constants.php`、`core/Constant.php`、`StringObject`、`MultibyteStringObject`、`ArrayObject`、`ObjectProxy`、`Exception/ArrayKeyNotExists`、`functions.php`、`alias*.php` | 已完成 |
| 2 | 协程基础设施：`core/Coroutine/*`（WaitGroup、Barrier、Server、Connection、Http/ClientProxy、Http/functions、Http2/MultiplexClient、functions）、`std/exec.php` | 已完成 |
| 3 | 连接池与数据库代理：`ConnectionPool` + `Database/*` 13 个文件 | 已完成 |
| 4 | 运行时 hook 层：`ext/*`、`core/Curl/Handler.php`、`core/Http/Status.php` | 已完成 |
| 5 | 服务与集成：`Server/Admin.php`、`Server/Helper.php`、`RemoteObject/*`、`Process/Manager.php`、`NameResolver/*`、`Thread/*` | 已完成 |
| 6 | 构建、加载机制与测试覆盖：`__init__.php` 依赖顺序、`vendor_init.php`、`tools/make-library`、trigraph、phpunit 套件 | 已完成 |

## 修复提交进度

截至 2026-11-16，文档已有的六批意见均已处理：已修复的问题、校正的审查结论和保留理由见各批修复记录。当前没有第七批。

| 批次 | 提交 |
| --- | --- |
| 1、2 | `20480a7` |
| 3 | `9f048b6` |
| 4 | `2e77b00` |
| 5 | `e6ca8c5` |
| 6 | `eaf8514` |

各修复记录中“后续批次待处理”等表述为当时的历史状态，以本表及对应批次最新修复记录为准。

## 结论标记约定

- **P0**：明确 bug，已在本机 `php -d swoole.enable_library=Off` 复现。
- **P1**：设计缺口 / 行为不一致 / 缺防护。
- **P2**：可优化项（现代化、重复代码、可读性）。
- 状态：`待确认` → `待修` / `已否定` / `已修`。review 阶段一律不直接改代码。

---

## 批次 1：基础层

审查日期：2026-11-16。以下 P0 均已复现；本机 PHP 8.4.14 ZTS、Swoole 6.3.0RC1。

### P0 明确 bug

| # | 位置 | 问题 | 复现 | 状态 |
| --- | --- | --- | --- | --- |
| 1-1 | `src/core/ArrayObject.php:115` | `throw new ArrayKeyNotExists($key)` 把 `mixed` 传给 `Exception::__construct(string $message)`，strict_types 下非字符串键抛 `TypeError` 而不是 `ArrayKeyNotExists` | `get(1)`、`get(1.5)`、`get(true)` → `TypeError: Exception::__construct(): Argument #1 ($message) must be of type string`；仅字符串键得到正常异常。**实际触发路径**：`swoole_table(1024, 'name')`（`src/functions.php:92`）报 TypeError | 已修 |
| 1-2 | `src/core/StringObject.php:167` | `char()` 边界判断用 `>` 应为 `>=` | `swoole_string('abc')->char(3)` → `Warning: Uninitialized string offset 3` 且返回 `''` | 已修 |
| 1-3 | `src/core/StringObject.php:83` | `trim()` 用 `if ($characters)`，`'0'` 是 falsy，字符掩码被忽略 | `swoole_string('000abc000')->trim('0')` 返回原串 | 已修 |
| 1-4 | `src/core/StringObject.php:120` | `append()` 为 `$this->string .= $str`，**修改原对象**却返回新对象，违反该类其它方法的不可变语义 | `$b = $a->append('b')` 后 `$a` 变成 `'ab'`，且 `$a !== $b` | 已修 |
| 1-5 | `src/functions.php:142` | `swoole_is_in_container()` 用 `strpos(...) > 0`：位置 0 命中被判 false；且 `file_get_contents('/proc/self/mountinfo')` 失败时 `strpos(false, ...)` 在 strict_types 下抛 TypeError（非 Linux / 权限受限） | `strpos('docker x','docker') > 0 === false`；`strpos(false,'x')` → TypeError | 已修 |

### P1 缺口

- **1-6 `MultibyteStringObject` 覆盖不全**（`src/core/MultibyteStringObject.php:14-62`）：只覆盖
  `length/indexOf/lastIndexOf/pos/rpos/ipos/substr/chunk`，`char()`/`reverse()`/`lower()`/`upper()`/`split()` 等仍走字节函数。
  `length()` 返回字符数、`char()` 按字节索引，语义矛盾：`"héllo"->length()=5` 但 `char(1)` 只返回 `0xc3`（半个 UTF-8 字节）；
  `reverse()` 得 `6f6c6ca9c368`（字节序被破坏）；`"ÄBC"->lower()` 得 `c3846263`（Ä 未转 ä）。状态：已修。
- **1-7 `ArrayObject` 的 ArrayAccess 与对象 API 不一致**（`:226` vs `:110`、`:261` vs `:267`）：
  `offsetExists()` 用 `isset()`、`exists()` 用 `array_key_exists()` → 值为 `null` 时 `isset($ao['k'])` 为 false
  而 `exists('k')` 为 true；`offsetGet()` 不包装类型而 `get()` 会把字符串/数组包装成 `StringObject`/`ArrayObject`。状态：已否定；保留原生数组风格的 ArrayAccess 行为，见下方修复记录。
- **1-8 `ArrayObject::randomGet()` 空数组**（`:411`）：`array_rand([], 1)` 抛未处理 `ValueError`，
  而 `first()`/`last()` 都返回 null。状态：已修。
- **1-9 `src/functions.php:215-225`**：`$wait_ready_fn()` 为无限 `while(true)`，无超时、无进程存活检测；
  remote object server 启动失败会永久挂起。与 `RemoteObject` 相关，批次 5 会再涉及。状态：已修。

### P2 可优化项

- **1-10** `StringObject` 返回类型不统一：`substr()`、`ipos()` 无返回类型；`ltrim()`/`rtrim()` 用 `: self`
  而其它用 `: static`；`indexOf`/`pos`、`lastIndexOf`/`rpos` 共 4 个方法只有 2 份逐字相同的实现（`:38-56`）。
- **1-11** `ArrayObject` 实现 `\Serializable`（PHP 8.1 起弃用）却又同时实现 `__serialize()`/`__unserialize()`，
  接口冗余；`unserialize()`（`:315`）直接 `unserialize($string)` 未设 `allowed_classes`，对不可信输入有对象注入风险。
- **1-12** `Iterator` 的 `current()`/`key()`/`rewind()`/`next()` 全靠 `#[\ReturnTypeWillChange]`，
  且 `rewind()`/`next()` 返回 mixed 而非 void；PHP 8.1+ 可补标准签名。
- **1-13** `swoole_container_cpu_num()`（`src/functions.php:158`）：`explode(' ', trim(...))` 遇多空格产生空字段
  → `floatval('')=0` → 除零得 INF → `intval(floor(INF))=0`；建议 `preg_split('/\s+/')` 并校验 period > 0。
  该函数在仓库内无调用点（公开 helper，CHANGELOG:121 记录过 cgroup v2 修复）。
- **1-14** `swoole_array_list(mixed ...$arrray)`（`:126`）参数名拼写 `$arrray`；
  `swoole_table()`（`:94`）用 `StringObject` 与字符串做松散 `switch` 比较，建议显式 `->toString()` 并补齐字段格式校验。
- **1-15** `swoole_init_default_remote_object_server()` 非协程分支 `pcntl_waitpid($pid, $status)` 后
  把 wait status 当退出码比较（`:340-342`），语义上应经 `pcntl_wexitstatus()`。

### 测试覆盖缺口

审查时上述边界均未被现有测试覆盖（修复后已补回归测试，见文末）：`tests/unit/StringObjectTest.php:107/120/129` 只测正常路径
（`testChar` 只测 `char(100)`，恰好绕开 `>=` 边界）；`tests/unit/ArrayObjectTest.php:161/592` 只测整数键和非空数组；
`tests/unit/` 无任何 `ArrayKeyNotExists` 测试；`swoole_is_in_container`、`swoole_table` 无测试。
`core/Constant.php` 232 个常量无重复值，仅有 1 处既有的 `@deprecated`（`:214`，CHANGELOG 已记录）。

### 复现命令

```bash
php -d swoole.enable_library=Off -r '
require "vendor/autoload.php";
use Swoole\StringObject; use Swoole\MultibyteStringObject; use Swoole\ArrayObject;
$s = new StringObject("abc");
set_error_handler(fn($n,$m) => print("  WARN: $m\n") ?: true); var_dump($s->char(3)); restore_error_handler();
$a = new StringObject("a"); $b = $a->append("b"); echo $a->toString(), " / ", ($a===$b?"same":"diff"), "\n";
echo (new StringObject("000abc000"))->trim("0")->toString(), "\n";
$m = new MultibyteStringObject("héllo"); echo bin2hex($m->char(1)), " ", bin2hex($m->reverse()->toString()), "\n";
$ao = new ArrayObject(["k"=>null]); var_dump(isset($ao["k"]), $ao->exists("k"));
try { (new ArrayObject([]))->randomGet(); } catch (\Throwable $e) { echo get_class($e), "\n"; }
try { (new ArrayObject(["a"=>1]))->get(1); } catch (\Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
try { swoole_table(1024, "name"); } catch (\Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
'
```

---

## 批次 2：协程基础设施

审查日期：2026-11-16。P0 均已复现。`MultiplexClient` 质量明显高于本批其它文件
（token / startupBarrier / abandonedStreams / 各处 `finally` 的竞态处理都很完整），
发现集中在**文档承诺与实现不一致**和**边界**，不是核心并发逻辑。

### P0 明确 bug

| # | 位置 | 问题 | 复现 | 状态 |
| --- | --- | --- | --- | --- |
| 2-1 | `src/core/Coroutine/Barrier.php:57` | `wait()` 用 `(int)($timeout*1000) > 0` 决定是否装 timer，`0 < $timeout < 0.001` 时**超时被静默忽略、永久等待** | `Barrier::wait($b, 0.0005)` 挂死（`timeout 3` 杀掉，exit 124）；`wait($b, 0.01)` 在 0.012s 正常返回 | 已修 |
| 2-2 | `src/core/Coroutine/Barrier.php:38` + `:64-68` | 静态 `$cancel_list` 残留：超时后 `wait()` 清理了标记，但 barrier 在等待者自己协程里析构时 `__destruct()` 走 else 分支又把 `cancel_list[cid]=true` 写回，无人再清理 | 反射读取：超时返回后 `{"1":true}`，barrier 析构后仍是 `{"1":true}`。cid 复用时后续 `wait()` 会因 `isset()` 为真而跳过 `yield()`，静默不等待 | 已修 |
| 2-3 | `src/core/Coroutine/Http/functions.php:190-194` | `request_with_stream()` 用 `if ($body)`：空响应体 / `"0"` 被判失败；随后 `error_get_last()` 为 null 时 `$error['message']` 为 null → `new Exception(null)` 在 strict_types 下 TypeError | `data://text/plain,0` → `Warning: Trying to access array offset on null` + `TypeError: Exception::__construct(): ... null given` | 已修 |
| 2-4 | `src/core/Coroutine/Http/functions.php:46-56` | `request_with_http_client()` 只校验 scheme，未校验 `host` | `request_with_http_client('http:foo','GET')` → `Warning: Undefined array key "host"` + `TypeError: Client::__construct(): Argument #1 ($host) must be of type string, null given` | 已修 |
| 2-5 | `src/core/Coroutine/Http/functions.php:105` | `request_with_curl()` 解析 Set-Cookie 用 `[$k,$v] = explode('=', $header[1])`（无 limit）：值含 `=` 被截断；无 `=` 的畸形头会 undefined offset | `session=abc=def; Path=/` → 值只剩 `abc` | 已修 |

### P1 缺口

- **2-6 `request_with_stream()` 与另外两个 driver 语义不一致**（`:189-195`）：成功时硬编码
  `new ClientProxy($body, 200, [], [])` —— 状态码永远 200、响应头与 Cookie 全丢；4xx/5xx 因未设
  `ignore_errors` 直接抛异常；未解析 `$http_response_header`。而 http_client driver 返回真实状态码/头/Cookie，
  curl driver 收集头与 Cookie。同一 `request()` API 换 driver 结果不同。状态：已修。
- **2-7 `request()` 未知 driver 静默回退**（`:28-32`）：`match` 的 `default` 走 http_client，
  配置拼错不报错。复现：`swoole_library_set_option('http_client_driver','typo-driver')` 后仍走 http_client 分支。状态：已修。
- **2-8 `batch()` / `map()` 忽略 `wait()` 返回值**（`src/core/Coroutine/functions.php:52`、`:99`）：
  超时后静默返回部分结果（未完成任务被置 `null`），调用者无法区分「任务返回 null」与「超时未完成」；
  且无超时信号。`map()` 文档（`:81`）写 "other means no waiting"，与实际的「无限等待」相反。状态：已修。
  （注：`batch/map/parallel` 的任务协程已用 `try/finally` 保护 `done()`，此项不涉及那处修复。）
- **2-9 `parallel(int $n)` 缺输入校验**（`:56-70`）：负 `$n` 时 `while ($count--)` 进入循环，
  子协程 `done()` 使计数为负并抛未捕获 `BadMethodCallException` → 进程 Fatal error（已复现，
  报 `WaitGroup misuse: negative counter`），而不是给出参数错误。状态：已修。
- **2-10 `MultiplexClient` 文档声明 `send()` 不可直接调用却未禁用**
  （`src/core/Coroutine/Http2/MultiplexClient.php:36-39`）：实际只覆写并禁用 `recv/read/write/goaway`；
  反射确认 `send` 仍 `declared in Client`；`tests/unit/Coroutine/Http2/MultiplexClientTest.php:323`
  的 `testFrameLevelApiIsDisabled` 也只测这四个方法。用户误用 `send()` 会让响应永久 parked、
  连接无法空闲关闭。状态：已修。
- **2-11 `MultiplexClient::$abandonedStreams` 慢速泄漏**（`:415-416`、`:224`）：条目只在收到对应
  响应或 `close()` 时清理；若服务端不回该 stream，长连接上每个超时请求都留一个条目。状态：已修。
- **2-12 `Coroutine\Server` 无生命周期状态机**（`src/core/Coroutine/Server.php:116-135`）：
  `start()` 未检查 `$running` 已为 true（可重入，同一 socket 起多个 accept 循环）；`shutdown()` 后
  可再次 `start()` 到已 cancel 的 socket。代码层面缺口，未做运行时复现。状态：已修。

### P2 可优化项

- **2-13** `WaitGroup::wait()` 的 `$this->waiting = false` 未放 `finally`（`WaitGroup.php:59-63`），
  与本文件 `batch/map/parallel` 已用 `try/finally` 保护 `done()` 的做法不一致；异常路径下状态会卡死。
  **防御性/一致性建议**：`Channel::pop()` 被取消通常返回 false 而非抛异常，此项未在运行时复现，不宣称是现存 bug。
- **2-14** `WaitGroup::__construct($delta)` 对负数静默忽略（`:25`），而 `add(-1)` 抛异常，行为不一致。
- **2-15** `std/exec.php:19` `foreach ($outputList as &$value)` 未 `unset($value)`，引用残留代码异味。
- **2-16** `request_with_*` 三个 driver 普遍用 `if ($data)` / `if ($headers)` 等 falsy 判断
  （`:58`、`:112`、`:178`），`$data='0'`、`0`、`[]` 被当成「不发送」。
- **2-17** `MultiplexClient.php:24-26` 在扩展未编译 HTTP/2 时整个文件 `return`，类静默不存在，
  用户 `new MultiplexClient()` 只得到 "Class not found"，无明确原因提示。
- **2-18 跨批次模式**：`new Exception($nonString)` 已出现两处 —— 批次 1 的
  `ArrayKeyNotExists($key)`（1-1）与本批 `new Exception($error['message'])`（2-3）。
  建议统一排查所有把 `mixed`/可为 null 的值直接传给 `Exception::__construct(string)` 的位置。
  （`src/core/Grpc/Status/Status.php:104` 的 `new RpcException($this)` 属 Composer-only 模块，不在本轮范围。）

### 测试覆盖缺口

- `tests/unit/Coroutine/HttpFunctionTest.php` 只有 6 个 test（get/post × 3 driver），
  全部走正常响应；`request_with_stream` / `request_with_curl` 无直接测试，空响应体、状态码、Cookie 均未覆盖。
- `tests/unit/Coroutine/BarrierTest.php` 有 `testWaitTimeout`，但未测 <1ms 超时与 `cancel_list` 残留。
- `tests/unit/Coroutine/Http2/MultiplexClientTest.php` 覆盖较全（10 个 test），但 `send()` 误用未覆盖。
- `deadlock_check()`（`functions.php:103`）无任何测试；`parallel` 负数、`WaitGroup` 异常路径无测试。

### 复现命令

```bash
# 2-1 微小超时（对比挂死与正常）
for t in 0.0005 0.01; do T=$t timeout 3 php -d swoole.enable_library=Off -r '
require "vendor/autoload.php";
use Swoole\Coroutine\Barrier;
\Swoole\Coroutine\run(function () {
    $b = Barrier::make(); $holder = $b;
    \Swoole\Coroutine\go(function () use ($holder) { \Swoole\Coroutine::sleep(5); });
    $t = microtime(true); Barrier::wait($b, (float) getenv("T"));
    echo "wait 返回 ", round(microtime(true)-$t,3), "s\n";
});
' 2>&1; echo "exit=$?"; done

# 2-2 静态残留
timeout 5 php -d swoole.enable_library=Off -r '
require "vendor/autoload.php";
$p = (new ReflectionClass(Swoole\Coroutine\Barrier::class))->getProperty("cancel_list");
\Swoole\Coroutine\run(function () use ($p) {
    $b = Swoole\Coroutine\Barrier::make(); $holder = $b;
    \Swoole\Coroutine\go(function () use ($holder) { \Swoole\Coroutine::sleep(0.2); });
    Swoole\Coroutine\Barrier::wait($b, 0.02);
    echo "wait 后 ", json_encode($p->getValue()), "\n";
    \Swoole\Coroutine::sleep(0.3);
    echo "析构后 ", json_encode($p->getValue()), "\n";
});
'

# 2-3 / 2-4（注意分别调用两个函数：2-3 是 stream driver，2-4 是 http_client driver）
php -d swoole.enable_library=Off -r '
require "vendor/autoload.php";
\Swoole\Coroutine\run(function () {
    foreach ([
        ["\\Swoole\\Coroutine\\Http\\request_with_stream", "data://text/plain,0"],
        ["\\Swoole\\Coroutine\\Http\\request_with_http_client", "http:foo"],
    ] as [$fn, $u]) {
        try { $fn($u, "GET"); }
        catch (\Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
    }
});
'

# 2-9
timeout 3 php -d swoole.enable_library=Off -d memory_limit=128M -r '
require "vendor/autoload.php";
\Swoole\Coroutine\run(function () { \Swoole\Coroutine\parallel(-1, function(){}); });
'

# 2-10
php -d swoole.enable_library=Off -r '
require "vendor/autoload.php";
$r = new ReflectionClass("Swoole\\Coroutine\\Http2\\MultiplexClient");
echo "send declared in ", $r->getMethod("send")->getDeclaringClass()->getShortName(), "\n";
'
```

## 批次 1 修复记录

修复日期：2026-11-16。

| 意见 | 处理结果 |
| --- | --- |
| 1-1 | 已修：缺失键转换为字符串后构造 `ArrayKeyNotExists`；覆盖整数、浮点、布尔、null 和字符串键。PHP 对有损浮点键转换本身的弃用提示保持不变。 |
| 1-2 / 1-3 / 1-4 | 已修：`char()` 覆盖等于长度、空串、负下标及越界；`trim('0')` 生效；`append()` 不再修改原对象。 |
| 1-5 | 已修：允许位置 0 命中，读取失败返回 false，不产生读取警告或 TypeError。 |
| 1-6 | 已修：补充多字节 `char/reverse/lower/upper/chunkSplit`。`split()` 按完整分隔符使用 `explode()`，UTF-8 分隔符不会被拆开，无须重写；已有非 ASCII 分隔符回归测试。 |
| 1-7 | 已否定为 bug：下标访问保留原始值，`isset()` 忽略 null，与 PHP 原生数组一致；`get()` 的包装及 `exists()` 的键存在语义保留。补充注释和测试，避免破坏 `??`、严格比较等已有用法。 |
| 1-8 | 已修：空数组 `randomGet()` 返回 null，非空数组继续包装字符串和数组。 |
| 1-9 | 已修：默认启动超时 10 秒，可用 `default_remote_object_server_startup_timeout` 设置正有限秒数。前台进程、锁竞争及已有 PID 等待均有期限，发现进程死亡立即报错；就绪检查实际连接 Unix socket，避免文件已 bind 但尚未 listen 的竞态；异常路径释放锁、管道和前台进程资源。 |
| 1-10 | 已修：补齐返回类型，`ltrim/rtrim/substr` 使用 `static`；位置查询别名复用主实现。 |
| 1-11 | 部分采纳：直接 `unserialize()` 默认 `allowed_classes=false`，第二参数允许显式白名单或 true。保留 `Serializable` 以读取旧的 `C:` 格式；同时实现魔术序列化方法的类不会产生“仅实现 Serializable”的弃用提示，故未移除兼容接口。原生全局 `unserialize()` 的类限制仍由调用者负责。 |
| 1-12 | 已修：Iterator 与 ArrayAccess 使用标准原生返回类型，`rewind/next` 为 void。 |
| 1-13 | 已修：使用空白分隔，校验 quota/period 是否有效、正数且有限；缺失、不可读、无限制和无效配额回退到宿主 CPU 数。 |
| 1-14 | 已修：更正 variadic 参数拼写；表字段直接解析为字符串，校验字段名、类型、段数和正整数字符串长度。 |
| 1-15 | 已修：统一通过非阻塞 `proc_get_status()` 获取解码后的退出码及终止信号，不再把 wait status 当作退出码；同步和协程分支均覆盖。 |

新增/扩展测试：`StringObjectTest`、`MultibyteStringObjectTest`、`ArrayObjectTest`、
`ContainerFunctionTest`、`TableFunctionTest`、`RemoteObject/DefaultServerTest`。
容器检测用独立子进程的文件流 fixture 模拟 procfs/cgroup，避免修改真实系统文件或测试进程的文件协议。
兼容性变化及启动超时选项已记录在 `CHANGELOG.md`。

## 批次 2 修复记录

修复日期：2026-11-16。本轮范围为批次 1、2，后续新增审查意见保留待处理。

| 意见 | 处理结果 |
| --- | --- |
| 2-1 | 已修：正超时向上取整为毫秒，最少 1ms；测试用独立 watchdog 防止旧实现挂住测试进程。 |
| 2-2 | 已修：移除按协程 ID 索引的静态取消列表，改用每次等待独立的状态对象；正常释放、超时和异常退出均在 finally 清理 timer/等待状态。覆盖同一协程先超时、析构旧 barrier、再等待新 barrier。 |
| 2-3 | 已修：stream driver 只把 false 当读取失败，空串与字符串 0 都有效；异常消息始终为字符串。 |
| 2-4 | 已修：构造 HTTP client 前验证 host，缺失时抛明确的 HTTP client Exception。 |
| 2-5 / 2-6 | 已修：curl/stream 共用响应头解析器，Cookie 按首个等号拆分、排除属性、忽略畸形项；stream 保留最后响应块的状态码、响应头和 Cookie，开启 ignore_errors 以返回 HTTP 4xx/5xx 的响应。本地服务器覆盖三个驱动及重定向最终响应。 |
| 2-7 | 已修：只接受 null、http_client、curl、stream，未知 driver 抛异常。 |
| 2-8 | 已修：保留原有超时返回部分结果且任务继续运行的兼容行为，新增可选的末尾引用输出参数 completed；false 表示等待超时或取消，true 表示全部完成。修正文档中非正超时的含义。 |
| 2-9 / 2-14 | 已修：parallel 的负任务数、WaitGroup 的负初始计数均在启动任务前抛 InvalidArgumentException；零任务仍有效。 |
| 2-10 | 已修：公开 send() 明确抛 BadMethodCallException，request() 内部通过 parent::send() 发送；禁用 API 测试加入 send()。 |
| 2-11 | 已修：累积 128 个未响应的 abandoned stream 后回收连接，清理 tombstone；在途请求按连接关闭处理，后续请求自动重连。此行为已写入类文档与 CHANGELOG，并用不结束响应的本地服务器验证。 |
| 2-12 | 已修：start() 拒绝重入与停止后的重启；进入 accept 循环后，所有退出路径均在 finally 重置 running 并标记停止。配置检查失败仍允许修正配置后首次启动。 |
| 2-13 | 已修：wait() 在 finally 恢复 waiting；测试注入 pop() 异常后验证 WaitGroup 仍可使用。 |
| 2-15 | 已修：引用 foreach 后显式 unset，保留 exec 原有输出规则。 |
| 2-16 | 已修：以 data !== null 判断是否发送；非数组数据转换为字符串，支持字符串 0、整数 0 和空数组。统一 curl/stream 的关联请求头格式，并保留 stream 的小数秒超时。 |
| 2-17 | 未采纳替代类/加载时抛错：HTTP/2 是可选扩展特性，父类缺失时必须跳过派生类声明，以保证整个内嵌库仍可启动。保留 class_exists(Client::class, false) 能力检测约定，在类文档中明确说明；不在全局加载时引入异常。 |
| 2-18 | 已核查：本轮两处可为 mixed/null 的异常消息输入已修复；其余当前相关错误路径传入扩展提供的错误字符串。后续批次仍保留其自身的专项审查范围。 |

新增/扩展测试：`Coroutine/BarrierTest`、`Coroutine/WaitGroupTest`、`Coroutine/FunctionTest`、
`Coroutine/HttpDriverTest`、`Coroutine/HttpFunctionTest`、`Coroutine/Http2/MultiplexClientTest`、
`Coroutine/ServerTest`。HTTP 边界用例使用本地临时服务，不依赖 httpbin 或外网。
`FunctionTest` 另补 deadlock_check() 的诊断输出测试。

验证记录：修改的源文件均通过 PHPStan level 5；26 个修改/新增 PHP 文件通过语法检查，
修改的内嵌源文件不含 C trigraph，PHP-CS-Fixer 与 git diff --check 通过。
合并 PHPUnit 回归集 219 项通过（1093 assertions），counit 回归集 193 项通过（948 assertions）。
无 HTTP/2 父类时的加载保护亦已用 php -n 验证。
外部服务集成测试未全量验证：本机缺 MySQL 服务配置及 MongoDB 扩展，现有 httpbin 用例也不在此回归集内。

---

## 批次 3：连接池与数据库代理

审查日期：2026-11-16。P0 均在**含批次 1/2 并行修复**的当前工作区复核过；
批次 3 涉及的 14 个文件（`ConnectionPool.php` + `Database/*`）均未被并行改动。
本批测试覆盖明显好于前两批：9 个文件、约 80 个 test。

### P0 明确 bug

| # | 位置 | 问题 | 复现 | 状态 |
| --- | --- | --- | --- | --- |
| 3-1 | `src/core/Database/DetectsLostConnections.php:115-124` | `causedByLostConnection()` 用 `str_contains` 匹配宽泛子串，把**普通 SQL 错误**误判为「连接丢失」→ `PDOProxy::__call()`（`PDOProxy.php:81-82`）会重连并**重新执行该调用** | 语法错误含 `"timed out"`、唯一键冲突含 `"Connection lost"`、普通文本含 `"No such file or directory"`、`Access denied for user` 全部被判为丢连接。列表（`:82-86`、`:111`）含 `'timed out'`、`'went away'`、`'Broken pipe'`、`'Connection lost'`、`'No such file or directory'`、`'failed to connect to'` 等短词；`'SQLSTATE[HY000] [1045] Access denied for user'`（`:73`）会让凭据错误被反复重连重试 | 已修 |
| 3-2 | `src/core/ConnectionPool.php:27-31` | 构造不校验 `$size`，`size <= 0` 时池永远造不出连接 | `new ConnectionPool(fn, 0)` 与 `-1` 均构造成功，`get(0.05)` 返回 false（`num < size` 恒 false，永不 `make()`）；无参数错误提示 | 已修 |
| 3-3 | `src/core/Database/RedisPool.php:37` | `if ($this->config->getAuth())` 用 falsy 判断，合法密码 `'0'` 被跳过认证 | `RedisConfig::getAuth()` 默认 `''`、`withAuth('0')` 合法；`bool('0') === false`。未在真实 Redis 上端到端复现，代码与语义已确认 | 已修 |

### P1 缺口

- **3-4 `RedisPool::clean()` 不重置 `WATCH` / `select()` 状态**（`RedisPool.php:61-62` 文档已承认）：
  借用者 `select(5)` 归还后，下个借用者落在 db 5，可能把数据写到错误的库；构造里
  `if ($dbIndex !== 0) $redis->select($dbIndex)`（`:40`）也不会在归还时恢复。`getMode()` 只报告
  MULTI/PIPELINE，检测不到 WATCH。属**已文档化但仍影响正确性**的缺口。状态：已修，细节见批次 3 修复记录。
- **3-5 丢连接后的透明重试会重放非幂等语句**：`PDOProxy::__call()` 重连后无条件
  `$ret = $this->__object->{$name}(...$arguments)`（`PDOProxy.php:81-82`）；`MysqliProxy::__call()`
  最多重试 3 次（`MysqliProxy.php:67`）；`PDOStatementProxy::reexecute()` / `MysqliStatementProxy`
  重连后重新 `prepare` + `execute`。事务内有 `openTransaction()` 保护，但**自动提交模式下
  INSERT/UPDATE 无保护**：若首次已在服务端执行、仅响应丢失，重试会重复执行。文档未提示此风险。状态：已修，细节见批次 3 修复记录。
- **3-6 `PDOPool::createDSN()` 拼接未转义**（`PDOPool.php:135-144`）：host/dbname/charset/unixSocket
  直接插入 DSN，含 `;` 会注入额外参数；`oci` 的 dbname 含 `:` 会破坏结构。状态：已修，细节见批次 3 修复记录。
- **3-7 `IO_METHOD_REGEX` 含 `close`**（`MysqliProxy.php:19`）：`close()` 因丢连接返回 false 时会触发
  重连并重试，与「关闭连接」的调用意图相反。状态：已修，细节见批次 3 修复记录。
- **3-8 `ConnectionPool::close()` 不关闭池中已有连接**（`ConnectionPool.php:118-126`）：只 close channel、
  置 null、num=0；PDO/mysqli/redis 对象依赖 GC，服务端资源与长连接可能延迟释放。状态：已修，细节见批次 3 修复记录。
- **3-9 `ConnectionPool::put()` 无来源校验**（`:104-116`）：重复归还同一连接、或归还非本池连接，
  会让 `num` 与池内实际数量失配，`fill()` 可能把 channel 填满并阻塞。状态：已修，细节见批次 3 修复记录。

### P2 可优化项

- **3-10** `DetectsLostConnections` 用 `str_contains`（大小写敏感），列表里重复
  `'server has gone away'` / `'Server has gone away'`（`:23-24`）；`'SERVER HAS GONE AWAY'` **漏判**（已复现）。
  建议 `stripos` 或统一小写并去重。
- **3-11** `causedByLostConnection()` 只看 message、不看 SQLSTATE/errno；
  `PDOProxy::errorInfoIsLostConnection()` 把 SQLSTATE 字符串强转 int（`'HY000' → 0`）当作
  `PDOException` 的 code（`PDOProxy.php:207`），`PDOStatementProxy.php:185` 同。
- **3-12** `MysqliProxy::change_user()` 把明文密码存进实例属性 `$changeUserContext`（`:212`）供重连重放；
  参数有 `#[\SensitiveParameter]`，但长期存活的池对象属性没有，内存 dump / 序列化会暴露。
- **3-13** `PDOPool.php:34`、`MysqliPool.php:50` 的 `@return` 写 "if the pool is full and the timeout is
  reached"，实际是「池空取不到 + 超时」，措辞错误。
- **3-14** 两个 StatementProxy 的 `bindParam/bindColumn/bindValue/bind_param` 无参数类型声明
  （`PDOStatementProxy.php:114-136`、`MysqliStatementProxy.php:112-128`）；与项目其它代码的
  strict 风格不一致（可能为兼容原生签名有意，需确认）。
  **已验证非 bug**：by-ref 变量存入 context 数组后用 `...$array` 展开传给 by-ref 参数时引用**能保持**，
  重连后绑定不会丢失，两处设计正确。
- **3-15** `MysqliStatementProxy.php:86` 用 `if ($this->queryString)`，空串 / `'0'` 会走 `stmt_init()`
  而不是 `prepare()`。
- **3-16** `MysqliProxy.php:72`、`MysqliStatementProxy.php:47` 用 `@` 抑制所有警告，可能掩盖参数错误等
  非连接问题。
- **3-17** `PDOConfig::hasUnixSocket()` 用 `!empty()`（`:63-66`），socket 路径 `'0'` 被判 false
  （与批次 1 的 falsy 模式一致）。
- **3-18 已排除的疑点**：`Channel::pop(0)` 与 `pop(-1)` 都是**无限等待**（实测均 deadlock），
  因此 `ConnectionPool` / `PDOPool` / `MysqliPool` 文档里 "0 or less waits with no time limit" 是**正确的**，
  不是缺陷。

### 测试覆盖缺口

- `tests/unit/Database/DetectsLostConnectionsTest.php` 只有 2 个 test：16 个正例 + 4 个反例。
  反例只覆盖「表不存在 / 唯一键冲突 / 语法错误 / 锁等待超时」，**没有**含
  `"timed out"`、`"Connection lost"`、`"No such file or directory"` 等文本的普通 SQL 错误 → 3-1 漏测。
- `tests/unit/ConnectionPoolTest.php`（11 tests）未测 `size` 为 0 / 负数、重复 `put()`、
  `close()` 后连接资源释放。
- `tests/unit/Database/RedisPoolTest.php`（7 tests）未测 `select()` 状态残留、`auth('0')`、
  WATCH 未结束。
- 三个 Pool 的 `clean()` 失败后 `parent::put(null)` 的 `num` 自愈路径未见专项测试。

### 复现命令

```bash
# 3-1 误判 + 3-10 大小写漏判
php -d swoole.enable_library=Off -r '
require "vendor/autoload.php";
use Swoole\Database\DetectsLostConnections as D;
foreach ([
  "SQLSTATE[42000]: Syntax error: unexpected \"timed out\" near ...",
  "SQLSTATE[23000]: duplicate entry \"Connection lost\"",
  "insert failed: No such file or directory",
  "SQLSTATE[HY000] [1045] Access denied for user root",
  "SERVER HAS GONE AWAY",
] as $m) { echo (D::causedByLostConnection(new PDOException($m)) ? "丢连接" : "不判为丢连接"), " | $m\n"; }
'

# 3-2 size 边界
S=0 timeout 2 php -d swoole.enable_library=Off -r '
require "vendor/autoload.php";
Swoole\Coroutine\run(function () {
    $p = new Swoole\ConnectionPool(fn() => new stdClass(), 0);
    var_dump($p->get(0.05));
});
'

# 3-18 排除疑点：pop(0) 与 pop(-1) 均为无限等待
for t in 0 -1; do T=$t timeout 2 php -r '
Swoole\Coroutine\run(function () { $ch = new Swoole\Coroutine\Channel(1); $ch->pop((float) getenv("T")); });
' 2>&1 | head -3; done
```

---

## 批次 4：运行时 hook 层

审查日期：2026-11-16。P0 均已复现；批次 4 涉及的 `src/ext/*`、`src/core/Curl/Handler.php`、
`src/core/Http/Status.php` 均未被并行改动。
`Curl/Handler.php`（1470 行）是本库最大的单文件，整体考虑得相当细（`CURLOPT_RESOLVE` 多地址、
代理、重定向、超时 deadline、回调），发现集中在**与原生 libcurl/sockets 行为的差异**和**测试缺口**。

### P0 明确 bug

| # | 位置 | 问题 | 复现 | 状态 |
| --- | --- | --- | --- | --- |
| 4-1 | `src/ext/standard.php:67-79` | `swoole_real_dns_get_record()` 接受 `$raw` 参数，但三处 `dns_get_record()` 调用（`:70/72/74`）都**不传第 5 参数**，`$raw=true` 完全无效；`swoole_dns_get_record()`（`:88`）把 `$raw` 传到远端也白传 | 原生 `dns_get_record(string,int,&,&,bool $raw=false)` 有该参数；hook 的三个分支均省略。代码证据 | 已修，见批次 4 修复记录 |
| 4-2 | `src/core/Curl/Handler.php:737` | `CURLOPT_HTTPHEADER` 用 `$headers[strtolower($headerName)]` 作键，**同名请求头被覆盖**；原生 curl 允许多个同名头（多个 Cookie / Accept / X-Foo） | `setOpt(CURLOPT_HTTPHEADER, ['X-Foo: a','X-Foo: b'])` → `customHeaders` 只剩 `{"x-foo":["X-Foo","b"]}` | 已修，见批次 4 修复记录 |
| 4-3 | `src/ext/curl.php:43-77` + `src/core/Curl/Handler.php:25-58` | `swoole_curl_getinfo()` / `getInfo()` 与原生 `curl_getinfo()` 行为不一致 | `CURLINFO_EFFECTIVE_METHOD`：hook 返回 `NULL`、原生返回 `'GET'`；未知 opt：hook 返回 `NULL`、原生返回 `false`；`getInfo()` 整数组缺 8 个 PHP 8.3+ 原生键（`appconnect_time_us`/`connect_time_us`/`namelookup_time_us`/`pretransfer_time_us`/`redirect_time_us`/`starttransfer_time_us`/`total_time_us`/`effective_method`），多出 `private`/`appconnect_time` 两个原生没有的键 | 已修，见批次 4 修复记录 |
| 4-4 | `src/ext/standard.php:21` | `swoole_mail()` 签名与原生 `mail()` 不一致：`array $headers`（原生 `array|string`），且**缺第 5 参数 `$additional_params`** | 反射对比：`swoole_mail(string,string,string,array)` vs `mail(string,string,string,array|string,string)`。传字符串头或 5 参数调用会失败 | 已修，见批次 4 修复记录 |

### P1 缺口

- **4-5 `Http/Status.php` 状态码表不完整/过时**：缺 `103 Early Hints`（RFC 8297）、
  `418 I'm a teapot`；`425` 仍是废弃的 `UNORDERED_COLLECTION` / `'Unordered Collection'`，
  而 RFC 8470 已把 425 定义为 **Too Early**（语义完全不同）；
  `413/414/416/408` 用旧名（`REQUEST_ENTITY_TOO_LARGE` / `REQUEST_URI_TOO_LARGE` /
  `REQUESTED_RANGE_NOT_SATISFIABLE` / `REQUEST_TIME_OUT`）。`getReasonPhrase(103/418/425)` 实测
  `'Unknown'/'Unknown'/'Unordered Collection'`。状态：已修；旧常量保留为别名，418 的保留状态见修复记录。
- **4-6 `ext/sockets.php` 的 flags 支持不全且差异提示不一致**：`swoole_socket_sendto()`（`:112`）
  与 `recvfrom()`（`:127`）对任何非 0 `$flags` 抛 `RuntimeException`，原生支持 `MSG_DONTWAIT` 等；
  `recv()` 用 `$flags & MSG_DONTWAIT ? 0.001 : 0`（`:94`）以 1ms 超时模拟非阻塞，
  但此处**没有** `socket_set_nonblock()` 里那段明确的「与 sockets 扩展行为不同」warning（`:286-292`）。
  状态：已修 MSG_DONTWAIT 支持及差异告警；其余不支持的 flags 明确拒绝。
- **4-7 `Handler::setOpt()` 的 `default` 抛 `CurlException`**（`Handler.php:884`），
  而原生 `curl_setopt()` 对未知选项返回 false（不抛）。可能是有意设计（CHANGELOG 其它场景也抛异常），
  但与原生兼容语义不同，会让「检查返回值」的调用方直接 fatal。状态：已校正原生行为并修复：未知编号抛 ValueError，已知但未实现的选项返回 false。
- **4-8 `ext/standard.php` 的 DNS/mail 函数全依赖 remote object daemon**（`:23/29/53/87/96`）：
  必须启动 `swoole_get_default_remote_object_client()` 的后台进程；`swoole_real_getmxrr()` 的
  `func_num_args() === 2` 分支（`:40`）是**死分支**（`swoole_getmxrr()` 总是传 3 个参数，`:56`），
  且 `swoole_getmxrr()` 在用户只传 2 参数时会把 `$weights` 赋为 null（`:58`）。状态：已修 MX 死分支与输出参数；保留现有 daemon 架构。
- **4-9 `Handler::reset()` / `close()` 每次 `new \ReflectionClass(self::class)->getDefaultProperties()`**
  （`:241`/`:261`）有反射开销；`close()` 后每次 API 调用都 `trigger_error` warning（`isAvailable()`，`:202`），
  与原生 `CurlHandle` 关闭后的行为不同。状态：已修：reset 缓存默认值，close 对齐 PHP 8 的无操作语义。

### P2 可优化项

- **4-10** `Http\Status` 是 `abstract class` 且只有常量与静态方法，可考虑 `final class` + 私有构造；
  `getReasonPhrase()` 对未知码返回 `'Unknown'`，PSR/常见实现多返回空串。 状态：保留：避免无缺陷依据的继承与未知码返回值变更，新增兼容性断言。

- **4-11** `swoole_curl_getinfo_integer()` 的 `$info[$key] * $scale`（`ext/curl.php:105`）对
  null / 非数值键静默得 0；`CURLINFO_*_T` 常量用 `defined()` 保护是对的。 状态：已修：缺失、null、非数值及非有限值返回 false。

- **4-12** `swoole_curl_getinfo()` 的 match 直接访问 `$info[...]` 键（`ext/curl.php:44-75`），
  靠 `DEFAULT_INFO` 保证存在，唯独 `CURLINFO_HEADER_OUT` 用 `?? ''` 兜底（`:55`），风格不统一。 状态：保留受 DEFAULT_INFO 保证的必有键；request_header 仍是可选键。

- **4-13** `swoole_socket_set_block()`/`set_nonblock()` 用 `__ext_sockets_*` 属性
  （`ext/sockets.php:271-295`）。已验证属性是**预定义**的、PHP 8.4 下无弃用警告，但依赖 C 类内部属性较脆弱。 状态：保留扩展预定义属性，新增阻塞模式切换与恢复的运行时回归。

- **4-14** `Handler::execute()` 里 `$proxyOptions ?? []`（`:984`）使用循环内可能未定义的变量；
  若 proxy 状态在循环中被清空会残留旧值。 状态：已修：每次循环显式清空 proxyOptions、proxy、proxyPort。

- **4-15** `Handler::__toString()` 返回 `"Object(id) of type (curl)"`（`:191-195`），
  原生 `CurlHandle` 不能转字符串。 状态：保留现有 Stringable 调试接口及测试，避免额外 API 破坏。

- **4-16** `ext/sockets.php` 的 `swoole_socket_recvfrom()` 忽略 `$length` 的实际读取长度，
  `recvfrom()` 读整个数据报再 `substr` 截断（`:144-157`）；对数据报语义正确，
  AF_UNIX > 64KiB 的截断已有 warning 说明（`:136-143`），属已文档化差异。 状态：保留已文档化的数据报语义与 64 KiB 限制；新增截断后完整消费数据报的回归。

### 已排除的疑点（验证的价值）

- `__ext_sockets_nonblock` 是**预定义属性**（`property_exists=true`），PHP 8.4 下设置不产生弃用警告。
- `swoole_socket_recv()` 失败 / EOF 时把 `$buffer` 设为 null（`ext/sockets.php:103/106`）
  与原生 `socket_recv()` 一致（原生失败与 EOF 都得到 `NULL`）。
- `swoole_socket_read(..., PHP_NORMAL_READ)` 对 CRLF 的处理（`"a\r"` + `"\n"`）与原生一致，不是拆分 bug。
- `CURLINFO_HEADER_OUT` 在 `exec()` 前返回 `''`、执行后由 `Handler.php:1205` 写入，设计正确。
- `CURLOPT_CUSTOMREQUEST='PATCH'` + `CURLOPT_POSTFIELDS` 能正常发 body：
  `POSTFIELDS` 会把 `$this->method` 设为 POST（`:713`），`$sendBody` 为 true，`setMethod()` 用 customRequest。

### 测试覆盖缺口（本批最突出）

- `tests/unit/Curl/HandlerTest.php` 有 23 个 test，且通过 `curl_init()`（hook 后）**间接覆盖**
  `ext/curl.php` 的包装函数 ✓；但 `CURLINFO_EFFECTIVE_METHOD`、`*_time_us`、未知 opt 等未覆盖。
- **`ext/sockets.php` 的 30+ 个函数完全无测试**：`tests/` 中没有任何 `socket_create` / `socket_recv` /
  `socket_write` 调用。
- **`ext/standard.php` 的 10 个 DNS/mail 函数完全无测试**：`dns_get_record` / `getmxrr` /
  `gethostbynamel` 在 `tests/` 中零调用；`dns_get_record(raw:)` 更无从覆盖。
- `Http/Status.php` 无专门测试。

### 复现命令

```bash
# 4-2 同名头覆盖 / 4-3 getinfo 差异 / 4-4 签名
php -d swoole.enable_library=Off -r '
require "vendor/autoload.php";
$h = new Swoole\Curl\Handler("http://127.0.0.1/");
$h->setOpt(CURLOPT_HTTPHEADER, ["X-Foo: a", "X-Foo: b"]);
echo "headers=", json_encode((new ReflectionClass($h))->getProperty("customHeaders")->getValue($h)), "\n";
echo "EFFECTIVE_METHOD swoole=", var_export(swoole_curl_getinfo($h, CURLINFO_EFFECTIVE_METHOD), true), "\n";
$ch = curl_init("http://127.0.0.1/");
echo "EFFECTIVE_METHOD 原生=", var_export(@curl_getinfo($ch, CURLINFO_EFFECTIVE_METHOD), true), "\n";
echo "未知 opt swoole=", var_export(swoole_curl_getinfo($h, 999999), true),
     " 原生=", var_export(@curl_getinfo($ch, 999999), true), "\n";
echo "键差: 仅原生=", json_encode(array_values(array_diff(array_keys(curl_getinfo($ch)), array_keys($h->getInfo())))), "\n";
'

# 4-5 Status
php -d swoole.enable_library=Off -r '
require "vendor/autoload.php";
foreach ([103, 418, 425] as $c) { echo $c, " => ", Swoole\Http\Status::getReasonPhrase($c), "\n"; }
'

# 4-1 代码证据
grep -n "dns_get_record\|\$raw" src/ext/standard.php

# 4-4 签名对比
php -r 'foreach (["swoole_mail","mail"] as $f) { $r=new ReflectionFunction($f); $p=[]; foreach($r->getParameters() as $x){$p[]=$x->getName();} echo "$f(",implode(",",$p),")\n"; }'
```

---

## 批次 5：服务与集成模块

审查日期：2026-11-16。批次 5 涉及的 19 个文件均未被并行改动。
**本批发现严重安全问题**：`RemoteObject\Server` 在默认配置下是一个无鉴权、无白名单的
任意函数/类调用接口，且请求参数做无限制反序列化。测试覆盖本身很好（约 20 个测试文件），
但缺的正是这些安全/边界用例。

### P0 明确 bug / 安全风险

| # | 位置 | 问题 | 复现 | 状态 |
| --- | --- | --- | --- | --- |
| 5-1 | `src/core/RemoteObject/Server.php:43/58-77/102/252/273/291` | **默认配置 = 无鉴权 + 无白名单的远程代码执行接口**：`$apiKey=''`，`onRequest()` 用 `$this->apiKey && ...` 短路，未配置时跳过鉴权；`allowedClasses`/`allowedFunctions` 默认 `[]`，`_new()` / `_call_function()` 用 `count(...) > 0 &&`，空列表即不限制 → `_call_function` 可调用 `system`/`exec`/`file_put_contents` 等任意函数，`_new` 可实例化任意类，`_call_method` 可调用任意对象任意方法 | 反射确认 `allowedClasses=[]`、`allowedFunctions=[]`、`apiKey=""`。默认绑 `127.0.0.1`，但 `swoole_get_default_remote_object_client()` 启动的 daemon 对本机所有进程开放，`ext/standard.php` 的 DNS/mail 函数也依赖它 | 已修；原生行为与边界说明见批次 5 修复记录 |
| 5-2 | `src/core/RemoteObject/Context.php:54`、`src/core/RemoteObject/Client.php:236` | 请求 POST 参数与服务端响应都直接 `unserialize()` / `@unserialize()`，**无 `allowed_classes`**，构成 PHP 对象注入路径；`Server::$allowedClasses` 只限制 `_new` 的类名，与反序列化无关 | 代码证据；与 5-1 叠加后本机任意进程可投递序列化 payload | 已修；原生行为与边界说明见批次 5 修复记录 |
| 5-3 | `src/core/RemoteObject/Server.php:113-115` | `onRequest()` 用 `method_exists($this, $method)` 从 URL 路径分发（`$method = str_replace('/', '_', request_uri)`），而 `method_exists` 对**私有/继承/public 方法**都返回 true，`$this->{$method}($ctx)` 在类内可调用私有方法 → 可调用 `start()`（再次启动服务器）、`onStart()`、`releaseConnection()`、`onRequest()`（递归）等非命令方法 | 实测 `_new`/`_call_function`/`start`/`onStart`/`releaseConnection`/`onRequest`/`__construct` 全部 `method_exists=true`。缺 handler 白名单 | 已修；原生行为与边界说明见批次 5 修复记录 |
| 5-4 | `src/core/Thread/Pool.php:137-140` | `shutdown()` 在 `start()` 前调用访问未初始化的 typed property `$running` | 实测 `Error: Typed property Swoole\Thread\Pool::$running must not be accessed before initialization` | 已修；原生行为与边界说明见批次 5 修复记录 |

### P1 缺口

- **5-5 `Process\Manager` 缺输入校验与生命周期保护**（`Process/Manager.php:51-73`）：
  `addBatch()` 负数 workerNum 静默忽略（`for` 不执行）；`start()` 可重入（覆盖 `$this->pool` 而旧进程仍在跑）；
  没有 `stop()`/`shutdown()`；无 worker 时由底层扩展抛 `Swoole\Exception: the parameter $worker_num must be greater than 0`，
  不是 Manager 自己的提示（实测）。状态：已修：输入和重入校验、shutdown、结束后释放 pool。
- **5-6 `Thread\Pool::start()` 主循环**（`Thread/Pool.php:116-123`）：`queue->pop(-1)` 无唤醒机制，
  `shutdown()` 后若线程未退出则永久阻塞；`$this->threads[$index]` 直接索引无校验，未知 index 会 warning
  并对 null 调 `join()`。`withClassDefinitionFile()` 分支（`:77-79`）直接 `require_once` 且不经过
  `isValidPhpFile()` 检查，与自动推导分支不一致。状态：已修关闭唤醒和索引校验；显式定义文件绕过检查是既有设计，保留。
- **5-7 `Server\Admin` 安全组合**（`Server/Admin.php:68/418/430/432/993-1017`）：`accessToken` 默认 `''`
  且 `if (!empty($token))` 跳过鉴权，配合 `Access-Control-Allow-Origin: *`，未配置 token 时任何本机浏览器页面
  可读 admin API；token 用 `!==` 而非 `hash_equals()`；`handlerGetStaticPropertyValue()` 用
  `ReflectionClass::getStaticPropertyValue()` 可读**私有静态属性**并 `var_export` 输出。
  （`get_included_file_contents` 有 `get_included_files()` 白名单，已排除任意文件读取疑点。）状态：已修：要求凭据、hash_equals、移除 wildcard CORS、限制可读静态属性。
- **5-8 `ServiceRegistration::stopServer()` 无幂等保护**（`NameResolver/ServiceRegistration.php:106-116`）：
  `Server/Helper` 的 `onWorkerExit`/`onWorkerStop`/`onBeforeShutdown` 都会调用它，对已关闭的 `wake` Channel
  重复 `close()`。实测 Swoole `Channel::close()` 第二次返回 `false`（不崩），但失败被静默忽略，
  `Registration::stopRenewal()` 同样重复 close。状态：已修：停止与关闭操作幂等。
- **5-9 `NameResolver::resolveCluster()` 的 waiter 超时后未从等待列表移除**（`NameResolver.php:370-376`）：
  超时协程抛异常后留下的 Channel 仍在 `pending[$key]['waiters']` 里，`notifyWaiters()` 用 1ms 超时 push（`:413`）
  丢弃结果；大量超时会让数组增长到 owner 完成。状态：已修：超时及取消的 waiter 在 finally 移除。
- **5-10 `NameResolver::requestBlocking()` 与协程分支返回结构不一致**（`NameResolver.php:262` vs `:354`）：
  headers 分别是 `$client->headers` 与 `array<string,string>`；`error_get_last()['message']`（`:336`）在
  `error_get_last()` 为 null 时产生 warning；`checkResponse()` 只接受 2xx，3xx 不跟随（与 `follow_location=>0` 一致）。状态：已排除：两个分支的响应头为字符串值；空 error_get_last 已有空合并保护；重定向策略有意保持。
- **5-11 `RemoteObject` 析构/定时器**（`RemoteObject.php:27-40`、`RemoteObject/Client.php:149`）：
  `__destruct()` 只 catch `Swoole\RemoteObject\Exception`，`release()` 抛其它 `Throwable` 时在析构中未捕获；
  `Timer::after` 闭包捕获 `$this`，定时器持有 Client 强引用，可能推迟 `__destruct()`。状态：已修析构释放的 Throwable 处理；保留短时定时器的必要强引用。

### P2 可优化项

- **5-12** `NameResolver/Exception.php` 有**重复文件头 docblock**（`:2-8` 与 `:12-18`）。 状态：已修，删除重复文件头。

- **5-13** `NameResolver::__construct(string $url, protected $prefix = ...)`（`:47`）`$prefix` 无类型声明。 状态：已修，prefix 声明 string。

- **5-14** `checkServerUrl()` 只校验 `port < 1`（`:186`），未校验 `> 65535`（`Cluster::add()` 有）；
  URL 里的 `user`/`pass` 在 `$baseUrl` 拼接时被丢弃（`:190`），但 `$this->info` 仍保留。 状态：已排除：parse_url 已拒绝超范围端口；Basic Authorization 在两个 HTTP 分支都有生成，新增回归。

- **5-15** `ServiceRegistration::configuration()` 未校验 `$candidates` 为数组（`:51`），
  传单个对象会让 `array_filter()` TypeError。 状态：已修，非数组候选配置抛 InvalidArgumentException。

- **5-16** `Admin.php:1235` 的 `assert($dataAll[0] === 'cpu')` 在生产 `zend.assertions=-1` 下是 no-op；
  `file_get_contents('/proc/stat')` 返回 false 时 `preg_split()` 会 TypeError（`:1231/1234`）。 状态：已修，读取失败或 CPU 首行格式异常时返回无数据，不依赖 assert。

- **5-17** `Server/Helper.php:310-329` 有 7 个空事件回调（`onBeforeReload`/`onAfterReload`/
  `onManagerStart`/`onManagerStop`/`onWorkerError` 等）；`file_put_contents` 写 stats 无错误处理（`:272`）。 状态：已修 stats 写入失败的日志处理；空事件回调保留框架钩子接口。

- **5-18** `NameResolver::resolveCluster()` 缓存淘汰是 FIFO 而非 LRU（`:394-396`，命中不更新位置）。 状态：已修为 LRU；缓存命中时移动到队尾。

- **5-19** `Registration::deregister()` 用 `while ($this->stopping) { Coroutine::sleep(0.001); }` 忙等（`:145-147`）。 状态：已修为 Channel 等待完成，并处理等待者取消。

- **5-20** `Cluster::pop()` 的 `random_int()` 可能抛 `Random\RandomException` 未捕获（`Cluster.php:44`）。 状态：保留 RandomException 传播；隐藏熵源失败会使非空服务池表现为空，缺乏合理回退依据。

- **5-21** `NameResolver::validateNode()` 用 `(new Cluster())->add(...)` 借对象做校验（`NameResolver.php:169`），
  可读性/开销可优化。 状态：保留共用 Cluster 校验；尚无性能证据要求复制规则或增加新的校验 API。

### 已排除的疑点（验证的价值）

- `Admin` 的 `get_included_file_contents` 有 `in_array(..., get_included_files())` 白名单，不是任意文件读取。
- `Admin` 传给 `getProcess*()` 的 pid 全部来自 `$server->master_pid` / `getWorkerPid()` 等，非用户输入；
  `shellExec("ps ... -p {$pid}")` 的 pid 经 `intval()` 处理，无命令注入。
- `[$k, $v] = swoole_string($l)->split(':')`（`Admin.php:1274`）对 `Swoole\ArrayObject` **能正常解构**。
- `swoole_socket_*` 相关的 CRLF / buffer null 行为已在批次 4 排除。

### 测试覆盖缺口

批次 5 覆盖约 20 个测试文件，是本轮最充分的一批；但**恰好缺了这些安全/边界用例**：
- 无「`RemoteObject\Server` 默认无 apiKey / 白名单」的安全性测试，也无 `onRequest()` 意外方法调用测试；
- `Context::getDataParam()` 的反序列化无 `allowed_classes` 场景无测试；
- 无 `Thread\Pool::shutdown()` 在 `start()` 前调用的测试；
- `Admin` 的 token/CORS 组合、私有静态属性读取无安全测试；
- `NameResolver` waiter 超时残留、`ServiceRegistration::stopServer()` 重复调用无测试。

### 复现命令

```bash
# 5-1 / 5-3 默认配置与可调用方法
php -d swoole.enable_library=Off -r '
require "vendor/autoload.php";
$s = new Swoole\RemoteObject\Server("127.0.0.1", 0);
$r = new ReflectionClass($s);
foreach (["allowedClasses","allowedFunctions","apiKey"] as $p) { echo "$p=", json_encode($r->getProperty($p)->getValue($s)), "\n"; }
foreach (["_new","_call_function","start","onStart","releaseConnection","onRequest","__construct"] as $m) { echo "$m=", var_export(method_exists($s,$m),true), "\n"; }
'

# 5-4 shutdown before start
php -d swoole.enable_library=Off -r '
require "vendor/autoload.php";
try { (new Swoole\Thread\Pool(Swoole\Thread\Runnable::class, 1))->shutdown(); }
catch (\Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
'

# 5-2 代码证据
sed -n "52,55p" src/core/RemoteObject/Context.php
sed -n "230,240p" src/core/RemoteObject/Client.php

# 5-7 默认 token
php -d swoole.enable_library=Off -r 'require "vendor/autoload.php"; var_dump(Swoole\Server\Admin::getAccessToken());'
```

## 批次 3 修复记录

修复日期：2026-11-16。本轮只处理批次 3；批次 4、5 及后续新增意见保留待处理。

| 意见 | 处理结果 |
| --- | --- |
| 3-1 / 3-10 / 3-11 | 已修：优先使用 PDO errorInfo 的 SQLSTATE/驱动码及 mysqli 的结构化错误字段；普通 SQL、约束、认证和序列化失败不归为断线。移除宽泛短词、去掉大小写重复项，用 stripos 实现大小写不敏感匹配。保留字符串诊断作为后备。原代码已使用 errorInfo[1] 作为驱动码，本次进一步保留 errorInfo[0] 的 SQLSTATE，并取消为判断而构造 PDOException 的做法。 |
| 3-2 | 已修：构造时拒绝非正 size。连接构造失败不会消耗容量；fill() 在池关闭后停止创建。 |
| 3-3 | 已修并端到端验证：密码字符串 0 不再跳过 AUTH；真实 Redis 测试同时覆盖普通密码和 0。 |
| 3-4 | 已修：归还时丢弃 MULTI/PIPELINE，清除 WATCH，恢复配置中的数据库；无法清理时丢弃对应连接。测试覆盖在 DB5 留下 WATCH，再借用时回到配置的 DB2 且事务不受旧 WATCH 影响。 |
| 3-5 | 已修：PDO/mysqli 及其 statements 默认不重放发生断线的调用，避免服务器已执行写入、只丢响应时重复提交。后续显式调用可重连；需要重复执行安全操作的调用者可用配置 withRetryOnLostConnection(true) 或代理 setRetryOnLostConnection(true) 显式开启。事务内仍不重试。旧重连测试显式开启重试，新测试模拟“写入已生效但响应丢失”，确认只执行一次。PDO 静默模式保留 errorInfo/errorCode，读取诊断不会触发重连并清除错误。 |
| 3-6 | 已修：拒绝网络 DSN 组件中的分号/NUL；PostgreSQL 按 libpq 规则引用并转义值；Oracle 验证 Easy Connect host/service/charset，IPv6 主机加方括号。SQLite 文件名保持其独立语义。 |
| 3-7 | 已修：连接与 statement 的 close() 均不在重试列表中；即使显式开启重试，也不会为关闭连接而新建连接。 |
| 3-8 | 已修可控的资源释放路径：close() 先标记关闭并清空计数，排空空闲连接，再唤醒等待者；借出的连接不被中途关闭，归还后释放。通用池新增可选析构回调，mysqli/Redis 池明确关闭原生对象。PDO 仅释放池持有的引用，外部 statement、对象引用和持久连接仍遵循 PDO 生命周期，不能承诺强制断开其原生会话。 |
| 3-9 | 已修：用 WeakMap 跟踪本池对象和借出/归还状态，拒绝外来和重复归还；增加 discard(connection) 明确丢弃某个借出连接。put(null) 只接受可唯一识别的借出/丢失连接，多个借用者时抛参数错误而不猜测对象。原生池清理失败时丢弃准确的对象，避免 num 失配。构造器须返回独立对象。 |
| 3-12 | 部分采纳：成功 change_user 后，密码用 PHP 8.2 的 SensitiveParameterValue 保存并在重连时取出；调试不再直接显示密码，失败的 change_user 不覆盖成功上下文。这不是内存加密，不能防止拥有完整进程内存读取能力的攻击者。 |
| 3-13 | 已修：PDO/mysqli get() 的超时描述改为无可用连接，不再写成池满。 |
| 3-14 | 已修 PDO 绑定方法的参数类型，保留 mixed 引用及参数引用语义；mysqli 的 bind_param/bind_result 原本已有原生兼容声明，无需重复修改。重连绑定测试继续通过。 |
| 3-15 | 已修：用 queryString !== null 判断是否重新 prepare；空串和字符串 0 不再被当成未准备语句。真实 MySQL 测试核对重新 prepare 后的原生 SQL 错误码。 |
| 3-16 | 已修：移除两个代理 __call() 及 statement 重建路径的 @，保留参数/非连接警告；测试核对告警可见性。连接池归还时的尽力清理仍独立处理关闭失败。 |
| 3-17 | 已修：hasUnixSocket 用非 null、非空串判断，路径字符串 0 被识别为已配置。 |
| 3-18 | 已排除：保留 timeout <= 0 无限等待语义，无需修改。 |

验证使用新建的隔离 MySQL 8.0（768 MiB）与 Redis 7.4（128 MiB）容器，禁用额外 swap，
不修改用户原有数据库/Redis 实例，不编译任何项目。另覆盖本机 SQLite。
PostgreSQL/Oracle 原生驱动在本机不可用，对应运行时集成用例不计为通过；其 DSN 构造/拒绝注入用例可独立运行。
兼容性变化和显式重试选项已写入 CHANGELOG。

最终验证：PHPUnit 142 项通过（404 assertions），counit 121 项通过（308 assertions）；
本批 ConnectionPool 与 Database 全部文件的 PHPStan level 5 检查通过。
20 个修改/新增 PHP 文件通过语法、格式及 trigraph 检查，git diff --check 通过。
测试使用 PHP 8.4.14 ZTS；上述计数不包含 PostgreSQL/Oracle 原生驱动集成测试。

---

## 批次 5 安全加固：默认远程对象服务器白名单

处理日期：2026-11-16。针对 5-1 / 5-2 的**第一步**：为默认 daemon 加上最小函数/类白名单，
把「默认不限制」改为「默认只放行库自身所需」。清单以 swoole-src 的
`ext-src/php_swoole_library.h`（内嵌库快照）及本库 `src/ext/standard.php` / `src/ext/mongodb.php` 为准，
并与 swoole-src `tests/swoole_runtime/remote_object/dns.phpt` 的 hook 路径核对过。

**收集结果**（独立的 `src/remote_object_config.php`，暴露为 `SWOOLE_LIBRARY_REMOTE_OBJECT_CONFIG` 数组常量；
不放在 `RemoteObject\Server` 类里，因为配置应与实现分离。该文件注册进 `src/__init__.php` 的 `files`
与 `src/vendor_init.php`，内嵌扩展与 Composer 两种模式都能读到）：

- 函数：`mail`、`checkdnsrr`、`swoole_real_getmxrr`、`swoole_real_dns_get_record`、`gethostbyaddr`。
  前三个入口与两个 `swoole_real_*` 服务端辅助函数都要在服务端可调用；
  `swoole_gethostbynamel()` 走 `System::getaddrinfo()`，不经远程协议，故不列入。
- 类：`MongoDB\Client`（`Swoole\MongoDB\Client` 通过 `create()` 创建）。

**落地方式**：`src/functions.php` 的 `swoole_init_default_remote_object_server()` 在用户
`default_remote_object_server_options` 之后用 `+= SWOOLE_LIBRARY_REMOTE_OBJECT_CONFIG` 补上两个白名单键，
用户可显式覆盖（传空数组即恢复不限制），原有 `worker_num` / `server_mode` 逻辑不变。
配置文件用 `define()` 而非顶层 `return [...]`：内嵌扩展模式下 `files` 是被 `_eval()` 执行的字符串，
`return` 的值会被丢弃，只有定义能留下来。
`tests/bootstrap.php` 显式放开白名单，因为远程对象测试演示的是通用 RPC（调用 `strlen`/`fopen`/`PDO` 等）。
CHANGELOG 的 `Backward-incompatible changes` 已记录自定义远程调用需显式声明。

**验证**：常量清单与 `ext/standard.php` 的 5 个 `call()` 完全一致；生成的默认 daemon 配置文件
`remote-object-server.php` 含 `allowed_functions` / `allowed_classes` 及具体值；
运行时实测 `strlen` 与 `create(stdClass)` 被拒绝、`gethostbyaddr('127.0.0.1')` 放行返回 `localhost`；
`DefaultServerTest`(16 tests)、`BootstrapIsolationTest`(1 test)、`RemoteObjectTest` 非 Mongo 部分
(17 tests / 323 assertions) 全部通过；`php -n -l` 与 `git diff --check` 通过。

**仍待处理（不在本次范围）**：5-2 的 `Context::getDataParam()` / `Client::request()` 无
`allowed_classes` 反序列化、5-3 的 `onRequest()` handler 白名单、5-1 的默认 `api_key` 为空、
`_call_method` 无方法白名单。函数/类白名单只缩小了 RCE 面，未完全消除。

---

## 批次 6：构建、加载机制与测试覆盖

审查日期：2026-11-16。范围：`src/__init__.php` 依赖顺序、`src/vendor_init.php`、
`tools/make-library/*`（Builder / functions / bin / tests）、`tools/build-library.php`、
trigraph 约束、`phpunit.xml.dist` 套件划分、`phpstan.neon.dist`、`.php-cs-fixer.dist.php`、
`.gitattributes`、测试覆盖总览。批次 4 的并行改动（`phpunit.xml.dist` 等）按当前工作区状态审查。

### P0 / P1 缺口

| # | 位置 | 问题 | 复现 | 状态 |
| --- | --- | --- | --- | --- |
| 6-1 | `tools/make-library/src/Builder.php:161/170/189` | 三处 shell 命令直接拼接未转义路径：`shell_exec('cd ' . $librarySrcDir . ' && git diff --shortstat')`、`git rev-parse HEAD`、`` `/usr/bin/env php -n -l {$php_file} 2>&1` ``。源码目录含空格即失败，含 `;`/`$(...)` 有命令注入风险 | 构造含空格目录的 fixture 跑 `tools/make-library/bin/make-library.php`：`sh: 1: cd: can't cd to /tmp/make` → `Unable to get commit id` exit 255；无空格路径 exit 0。对比 `tools/make-library/tests/run.php:178/182/204` 用了 `escapeshellarg`，不一致 | 已修，见批次 6 修复记录 |
| 6-2 | `tools/make-library/src/Builder.php:158-168` | `checkFileChange` 用 `git diff --shortstat` 只统计**未暂存**改动；已 `git add` 的源改动不会被发现，而 header 的 `$Id` 用的是 `git rev-parse HEAD` → 声明的 commit 与实际打包内容不一致 | 实测：已暂存改动时 `git diff --shortstat` 为空、`git diff --cached --shortstat` 非空。CI checkout 干净故未暴露，本地/第三方集成会踩到 | 已修，见批次 6 修复记录 |
| 6-3 | `src/remote_object_config.php`（本轮新增文件） | 顶部 `/** ... */` 文档块被 cs-fixer 判为应为 `/*`，CI 的 `composer cs-fix -- --dry-run` 会失败 | cs-fixer dry-run 报 1 file can be fixed（diff 仅 `/**` → `/*`） | **已修**（改为 `/*` 并复核 0 violations；其余改动文件 dry-run 也全部通过） |

### P2 可优化项

- **6-4 trigraph 转义分层不一致**：转义只在 `tools/build-library.php:61` 做，独立入口
  `tools/make-library/bin/make-library.php` 不做（README:66 已说明）。实测含 `??=` 的 fixture：
  独立入口生成的 header 保留 1 处未转义 `??=`，`build-library.php` 入口为 0 处（已转为 `?\?=`）。
  `CLAUDE.md:81` 却写「`src/` 不得含 trigraph……只有 build-swoole.yml 能发现」，
  与「生成器会自动转义」矛盾。建议把转义下沉到 `Builder::make()`，让两个入口一致，并订正文档。 状态：已修：转义移至 Builder::make()，两入口逐字节对比并覆盖全部九种序列。

- **6-5** `unCamelize()`（`tools/make-library/src/functions.php:76-82`）第二个 `preg_replace` 的替换串
  引用了不存在的 `${3}`（正则只有 2 个捕获组），实测无警告且结果正确，属多余；
  更值得关注的是生成的 C 变量名**没有碰撞检测**：当前 60 个 files 无碰撞，但 `fooBarBaz` 与
  `foo_bar_baz` 都会得到 `swoole_library_source_foo_bar_baz`，重复会生成重复 C 符号。 状态：已修：删除不存在的捕获组，检测非法 C 标识符及源文件间符号碰撞。

- **6-6** `Builder::removeComments()`（`:129-154`）是死代码：`__init__.php` 的
  `stripComments => false`，构造函数 `!empty()` 得 false，`make()` 从不调用。 状态：已排除：主库关闭该选项不等于工具不支持；保留 stripComments 并覆盖 true/false。

- **6-7** `Builder.php:72` `stripComments` 赋值结尾多一个分号（`;;`）。因 `.php-cs-fixer.dist.php`
  的 Finder 排除了 `tools/`，CI 不会抓到。 状态：已修：删除多余分号。

- **6-8** `Builder::__construct` 的 `$initFile = $librarySrcDir . '/__init__.php'`（`:26`）
  用原始参数，而 `$this->librarySrcDir` 是 `realpath()` 结果；symlink / 含 `..` 的相对路径下两者可能不一致。 状态：已修：加载配置与扫描统一使用 realpath 后的源目录。

- **6-9** `src/vendor_init.php:24-25` 与 `CLAUDE.md:98` 的注释说「只加载 PSR-4 根 `src/core`
  之外的文件」，但 `vendor_init.php` 实际也 require 了 `core/Coroutine/Http/functions.php` 与
  `core/Coroutine/functions.php`（在 `src/core/` 内的函数文件）。两份清单本身**完全一致**
  （10 个 `src/` 根外文件 + 2 个 `core/` 下函数文件 = 12 个 require），只是注释概括不准。 状态：已修注释；新增 manifest/vendor_init 顺序与完整性检查。

- **6-10** `phpstan.neon.dist` 注释里的 `./src/swoole` / `./src/swoole_library` 指 ide-helper
  包内目录，与本仓库 `src/` 同名，容易误读。 状态：已修注释，写明 vendor/swoole/ide-helper 包内完整路径。

- **6-11** `phpunit.xml.dist` 的 concurrent suite 用**手工 exclude 列表**维护（当前 15 条），
  新增需串行运行的测试容易漏加；`tests/unit/Thread` 整目录排除后由 `tests.yml` 的
  `thread-tests` job 在 ZTS 镜像单独跑（已确认，不是遗漏）。 状态：已加回归校验 serial 与 concurrent 的文件排除项一一对应；仍由维护者判断测试是否需串行。

- **6-12** `tools/make-library/tests/run.php` 第 7 组 `exec($cmd, $outLines, ...)` 未重置
  `$outLines`（`exec` 追加），stdout 混有第 6 组输出；19 条断言覆盖 ignore 逻辑，
  但**没有** trigraph / `unCamelize` 碰撞 / `checkFileChange` 的测试。 状态：已修：测试进程用参数数组和独立输出，新增路径、Git 状态、trigraph、碰撞、失败路径与双入口回归。

- **6-13 测试覆盖总览**：`tests/unit` 共 112 个测试文件。按类名/函数名精确 grep，
  唯一完全未出现在 `tests/` 的内嵌文件是 `core/RemoteObject/ProxyTrait.php`
  （仅 `examples/remote-object/client.php` 与 `src/ext/mongodb.php` 使用），无单元测试。
  其余内嵌模块的类名/函数名都能在 tests/ 中找到（含间接覆盖）。 状态：已补 ProxyTrait 真实本地 RPC 回归，覆盖属性、方法、调用、字符串、数组、迭代与计数。

### 已排除的疑点（验证的价值）

- **依赖顺序**：用全限定名精确检查 50 个库内类的 `extends`/`implements`，**0 违规**，
  `__init__.php` 的 "Sort by dependency" 约束成立。初版按短名匹配的 4 条"违规"全是
  跨命名空间误报：`MultiplexClient extends Client` 是 C 扩展类 `Swoole\Coroutine\Http2\Client`
  （不是 `ext/mongodb.php` 的 `Swoole\MongoDB\Client`）；`MysqliException`/`Curl\Exception`
  extends 的是 PHP 内置 `\Exception`；`Thread\Pool` 并不 extends `Runnable`（只是运行时
  `is_subclass_of`，`Runnable.php` 排在它之后无害）。
- **`files` 与 `vendor_init.php` 一致性**：按「是否可被 `Swoole\` PSR-4 自动加载」划分，
  60 个 files = 48 个类文件 + 12 个需显式 require 的非类/根外文件，与 `vendor_init.php`
  的 12 个 require **完全一致**，无缺失无多余。
- `tests/unit/Thread` 不在两个套件里是**有意**的（ZTS 要求，`thread-tests` job 单独跑）。
- `tools/make-library/tests/run.php` 19 assertions / 0 failure；端到端
  `php tools/build-library.php dev` 生成 header 成功（含新配置文件）。

### 复现命令

```bash
# 6-1 含空格源码目录
TMP="/tmp/make lib $$"; mkdir -p "$TMP/src"; printf '<?php return ["name"=>"swoole","checkFileChange"=>false,"output"=>getenv("OUT"),"stripComments"=>false,"files"=>["a.php"]];' > "$TMP/src/__init__.php"; printf '<?php\nfunction a(){}\n' > "$TMP/src/a.php"; printf '<?php\n' > "$TMP/src/vendor_init.php"; (cd "$TMP" && git init -q && git add -A && git -c user.name=t -c user.email=t@t commit -q -m i); OUT="$TMP/out.h" php tools/make-library/bin/make-library.php "$TMP/src"

# 6-2 已暂存改动不被发现
TMP=/tmp/cfc; mkdir $TMP; cd $TMP; git init -q; git config user.email t@t; git config user.name t; echo a > f; git add f; git commit -q -m i; echo b > f; git add f; git diff --shortstat; git diff --cached --shortstat

# 6-3 cs-fixer
php -d swoole.enable_library=Off vendor/bin/php-cs-fixer fix --dry-run --diff src/remote_object_config.php

# 依赖顺序 / 清单一致性：见本轮会话脚本（FQN 匹配 + 非类文件划分）
php tools/make-library/tests/run.php
```


---

## 批次 4 修复记录

修复日期：2026-11-16。批次 3 已提交为 `9f048b6`；本轮只处理批次 4。
批次 5 的独立默认白名单改动与批次 6 审查内容均保留，本批未扩展其修复范围。

- **4-1 / 4-4 / 4-8**：DNS helper 无条件向原生转发第 5 参数 `raw`；mail 接受字符串或数组头并转发
  `additional_params`。使用原生参数名，旧 `headers:` 命名参数的迁移已写入 CHANGELOG。
  MX helper 始终收集主机和权重；hook 只写回调用方提供的输出参数，不再序列化输出变量的旧值，
  未初始化、非数组甚至闭包旧值均可作为输出。现有 remote-object daemon 架构保留。
- **4-2**：同名 HTTP 请求头保存为列表，发出独立字段行；跨 origin 重定向移除整组
  Authorization/Cookie。补充 `CURLOPT_RESOLVE` 自动 Host 的非默认端口与 IPv6 括号。
- **4-3 / 4-11 / 4-12**：getinfo 补充有效方法与受运行时常量保护的微秒字段；完整数组排除
  private/appconnect_time 秒数，个别 selector 仍可查询。未知 selector（含显式 0）返回 false，
  null 返回完整数组。整数 helper 对缺失/null/非数值/非有限值返回 false。
  这些微秒字段与 effective_method 在实测 PHP 8.2 已存在，并非仅 PHP 8.3+。
  可选 POSTTRANSFER_TIME_T 也按常量检测；底层 HTTP client 没有的统计保留为 0，未新增精确计时能力。
- **4-5**：补齐 103、418、425 与现代状态码名/短语，保留原有数字别名。418 在 RFC 9110 / IANA
  中是保留的 Unused；`IM_A_TEAPOT` 与短语是兼容惯例，不宣称它是标准分配。
- **4-6 / 4-13 / 4-16**：send/sendto/recvfrom 接受 MSG_DONTWAIT；与 recv 的模拟非阻塞路径
  统一警告说明 1 ms 超时、协程可能挂起及 ETIMEDOUT 差异。sendto 在 finally 恢复发送超时。
  MSG_PEEK 仍使用原生 peek；其余未实现的 flags 显式拒绝。数据报一次读取后截断的行为、
  AF_UNIX 超过 64 KiB 的既有告警与预定义 C 属性均保留，并新增运行时回归。
- **4-7**：原审查中“原生未知选项返回 false”的判断不适用于本库最低 PHP 8.2。实测
  PHP 8.2 / 8.4 / 8.5 均抛 ValueError；hook 已对齐。已知但 hook 未实现的选项返回 false，
  设置错误 48（PHP 常量名 CURLE_UNKNOWN_TELNET_OPTION）。
- **4-9 / 4-14**：reset 缓存反射默认值；close 对齐 PHP 8 CurlHandle 的无操作语义，重复关闭后
  仍可查询与配置，资源由对象生命周期释放。清空每次重定向循环的代理局部变量。
  验证中发现内部写回调强引用句柄形成循环；已改为 WeakReference 并断言无需 GC 即释放句柄。
  原有相对重定向的一处空合并赋值等价展开，避免内嵌 C trigraph。
- **4-10 / 4-15**：未改变 Status 的可继承结构、未知码 `Unknown` 返回值及 Handler 的 Stringable
  调试接口；这些是既有 API，没有缺陷证据要求本轮破坏兼容。

**验证**：PHPUnit 44 tests / 300 assertions；counit concurrent 29 tests / 203 assertions，
均使用当前 PHP 8.4 / Swoole 6.3，并通过独立、128 MiB 内存上限的本地 HTTPbin 服务测试。
PHP 调试版退出无内存泄漏报告。mail/DNS 使用本地 RPC stub 验证转发；raw 使用原生参数校验
在查询之前失败的路径验证，未发送邮件或外部 DNS 查询。另以原生 PHP 子进程对比默认
getinfo、未知选项和 close 行为。5 个受影响源码文件 PHPStan 无错误；12 个 PHP 文件
通过 PHP 8.2 / 8.4 / 8.5 语法检查与 cs-fixer dry-run；PHPUnit XML、trigraph 与 diff 检查通过。
未重新编译扩展，也未宣称全仓库套件通过。

**依据**：[PHP curl_getinfo](https://www.php.net/manual/en/function.curl-getinfo.php)、
[PHP dns_get_record](https://www.php.net/manual/en/function.dns-get-record.php)、
[IANA HTTP Status Code Registry](https://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml)。

---

## 批次 5 修复记录

修复日期：2026-11-16。第四批已提交为 `2e77b00`；本轮处理第五批，保留第六批意见与独立配置文件的格式修复。

- **5-1**：独立 RPC Server 要求非空 api_key，并沿用库默认函数/类白名单；调用方仍可显式用空数组
  允许所有函数/类。默认 daemon 自动生成并复用 32 字节随机密钥，密钥与生成脚本均为 0600，
  用完整临时文件发布；目录要求属于当前用户且无 group/other 写权限，密钥不得为符号链接。
  Client 自动读取默认密钥，verbose 不打印密钥；鉴权用 hash_equals，字符串 '0' 也不绕过。
  老 daemon 升级需重启。允许调用的类仍可使用其公开方法；没有引入额外的方法级权限体系，
  api_key 代表受信任 RPC 调用方，不能把任意业务类白名单当沙箱。
- **5-2**：请求和响应共用 Context::decode，固定只允许 RemoteObject 协议引用，拒绝其余对象、
  损坏数据和过深/循环结构。按用户意见没有引入 allowedSerializedClasses 或可配置的业务类名单；
  业务对象通过远程 create() 和协议引用传递。新增本地 __wakeup 探针证明拒绝的类不执行回调。
  PHP unserialize 并非 eval；原意见中的执行风险指本地可加载类的魔术方法/对象注入。
  仍使用 PHP 序列化协议，类限制与鉴权共同收紧入口，不宣称能安全解析任意不可信 PHP 载荷。
- **5-3**：分发改为固定协议路由列表，包括释放批次与 iterator/offset 命令。原复现遗漏了
  Context::getHandler() 把路径 '/' 转成 '_' 的步骤，因此 /start 并不会直接调用 start()。
  仍可命中 __construct（/_construct）或子类中意外的下划线方法；新增子类 _maintenance 探针
  验证这些非协议方法被拒绝。这是明确限制路由边界，不能把原文列出的所有方法都当作已证实可执行。
- **5-4 / 5-6**：按用户意见，Thread\Pool 在构造方法创建 Atomic(0)，属性保持非 nullable；
  start() 置 1，启动前或重复 shutdown() 无害。关闭时唤醒管理队列，过滤无效退出索引，
  runner 创建失败也恢复运行状态。结束仍会 join 工作线程，未加入强制终止能力。
  withClassDefinitionFile() 是明确指定可信启动文件并绕过 php-parser 的现有接口，Composer suggest
  和文件注释已有说明；不强制给显式文件增加 parser 依赖。
- **5-5**：Manager 拒绝非正数批量、空 worker、重入和运行中改配置；增加 shutdown()；
  start() 结束后释放 pool，避免 native 回调保留 Manager 的循环引用。
- **5-7 / 5-16**：Admin 必须配置非空 access_name/access_secret，移除 wildcard CORS，
  使用 hash_equals；每个监听器捕获自己的 token，避免另一次启动改变旧监听器鉴权。
  仅可查看已初始化的 public static 属性，拒绝 private/protected、实例和未初始化属性。
  HTTP handler 弱引用 server，避免保留循环；/proc 读取失败及 CPU 首行异常返回 [0]。
- **5-8 / 5-9 / 5-18 / 5-19**：服务注册停止及 wake Channel 关闭幂等；并发注销用完成 Channel
  广播，等待者取消会退出。服务发现等待者在 finally 移除并关闭自己的 Channel，owner 不再保留
  已超时/取消的等待者；缓存命中更新顺序，淘汰 LRU。
- **5-10 / 5-14**：实测阻塞与协程 HTTP 请求都发送 URL 中的 Basic 凭据，响应头值均为 string。
  baseUrl 故意不保留 user/pass，凭据通过 Authorization 转发；error_get_last 的空合并已有保护。
  parse_url 在进入端口判断前已拒绝 65536；新增高端口回归。保持不跟随注册中心重定向的策略。
- **5-11**：RemoteObject 析构捕获全部 Throwable，移除错误回溯打印；异步 flush 的异常也被包含。
  释放定时器短暂持有 Client 是为了确保最后一批释放发送完毕；已有对象/Client 生命周期回归证明
  释放完成后引用消失，保留该机制。
- **5-12 / 5-13 / 5-15 / 5-17**：删除重复文件头、prefix 类型补齐、候选 resolver 数组校验、
  stats 文件写失败记录日志；空事件保留为扩展框架调用接口。
- **5-20 / 5-21**：保留随机源失败的显式异常及复用 Cluster 的节点校验，避免隐藏失败或复制验证规则。

依据：[PHP unserialize 手册](https://www.php.net/manual/en/function.unserialize.php)。

**验证**：相关 PHPUnit 111 tests / 672 assertions；独立 ZTS 线程套件 54 tests / 108 assertions；
counit concurrent 78 tests / 548 assertions，均通过，PHP 调试版退出无泄漏报告。
13 个受影响源码文件 PHPStan 通过；23 个 PHP 文件通过 cs-fixer dry-run、PHP 8.2 / 8.4 / 8.5
语法检查；PHPUnit XML、受影响内嵌源码 trigraph 与 git diff --check 均通过。
未启动外部 MongoDB/Consul/Nacos/Redis 服务，对应集成测试未运行；未重新编译扩展。
新增 Admin 与阻塞 HTTP 对比测试已加入 serial 套件；第六批及后续意见未在本轮修复。

---

## 批次 6 修复记录

修复日期：2026-11-16。第五批已提交 `e6ca8c5`；本轮仅处理第六批。

- **6-1 / 6-2 / 6-8**：Builder 用 proc_open 参数数组执行 Git 与 PHP_BINARY -n -l，
  工作目录和 __init__.php 统一取 realpath，源码路径不经 shell。语法判断依据退出状态，
  Git 失败会明确中止。checkFileChange 改用 git status --porcelain --untracked-files=all，
  检查整个仓库的已暂存、未暂存和未跟踪变更；不再给脏树生成带 clean commit 标记的头文件。
  保留 dev 模式，行为和迁移方式已写入 CHANGELOG / README。
- **6-3**：保留审查期间对 remote_object_config.php 文档块的格式修复，并复核 cs-fixer。
- **6-4**：trigraph 转义移入 Builder，包装入口只负责启动生成器与传递退出状态。
  独立入口覆盖全部九种序列，解码 C 字符串验证 PHP 源码字节往返一致；
  两入口还分别生成真实 src/ 的头文件并逐字节比较。CLAUDE.md 与工具 README 已同步。
- **6-5 / 6-7**：删除多余捕获组和分号；写文件前检测非法 C 标识符及两个文件归一化后
  的符号碰撞，诊断包含源文件名。已有输出不会在这些检查失败时被覆盖。
- **6-6**：不删除 removeComments。stripComments 是工具的受支持配置，主库设置 false
  只表示保留自身注释；新增 true/false 回归，也验证类似注释的字符串字面量不被剥离。
- **6-9 / 6-10**：修正 Composer 显式加载文件和 ide-helper 路径注释。新 LibraryManifestTest
  通过 PHP AST 识别 PSR-4 类文件与函数文件，校验 vendor_init 的内容和顺序；同时解析
  extends / implements / trait use 的完整类名，检查嵌入文件依赖顺序，避免短名误报。
- **6-11**：保留现有套件结构及独立 ZTS job；新测试检查 serial 文件存在、无重复，且与
  concurrent 文件排除项完全一致。识别一个新测试是否改变全局状态仍需维护者判断，
  本轮没有声称自动完成这种分类。
- **6-12**：工具测试以参数数组独立捕获每次输出，消除 exec 追加残留。新增清洁树、
  staged/unstaged/untracked、Git 失败、语法失败、带引号/命令替换字符的路径、符号碰撞、
  trigraph、配置选项和规范路径测试。CI 在 nightly PHP 8.2 / 8.5 加入该独立工具回归。
- **6-13**：新增 ProxyTraitTest，使用测试专用本地 daemon 验证透传行为，不依赖外部 MongoDB。

**验证**：生成器工具在 PHP 8.2 / 8.4 / 8.5 下各 123 assertions / 0 failures；
新增 PHPUnit 6 tests / 125 assertions、counit concurrent 6 tests / 125 assertions 均通过，
PHP 调试版退出未报告泄漏。5 个受影响工具/加载文件 PHPStan 无错误；8 个 PHP 文件通过
PHP 8.2 / 8.4 / 8.5 语法检查；4 个纳入主库格式规则的文件 cs-fixer dry-run 无变更。
工具保持自身既有格式；workflow YAML 和 git diff --check 通过。只生成头文件并运行 PHP
语法检查，没有运行 C/C++ 编译或链接，也未宣称 CI 服务集成套件已执行。
