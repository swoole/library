<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\NameResolver;

use Swoole\Coroutine\Http\ClientProxy;
use Swoole\Tests\TestCase;

trait RecordingRequests
{
    public array $requests = [];

    public string $body = '[]';

    public int $status = 200;

    protected function request(string $method, string $path, mixed $body = null, array $query = [], array $headers = []): ClientProxy
    {
        $this->requests[] = compact('method', 'path', 'body', 'query', 'headers');
        return new ClientProxy($this->body, $this->status, [], []);
    }
}

/**
 * @internal
 * @coversNothing
 */
class HttpBackendTest extends TestCase
{
    public function testConsulHealthyNodesAndAddressFallback(): void
    {
        $r       = self::consul()->withToken('secret')->withDatacenter('dc2')->withNamespace('app');
        $r->body = json_encode([
            ['Service' => ['Address' => '', 'Port' => 80, 'Weights' => ['Passing' => 500]], 'Node' => ['Address' => '::1'], 'Checks' => [['Status' => 'passing']]],
            ['Service' => ['Address' => '127.0.0.2', 'Port' => 80], 'Checks' => [['Status' => 'critical']]],
            ['Service' => ['Address' => '127.0.0.3', 'Port' => 80, 'Weights' => ['Passing' => 0]]],
        ]);
        $cluster = $r->getCluster('a/b?c');
        self::assertSame(1, $cluster->count());
        self::assertSame(['host' => '::1', 'port' => 80, 'weight' => 500], $cluster->pop());
        $request = $r->requests[0];
        self::assertSame('/v1/health/service/swoole_service_a%2Fb%3Fc', $request['path']);
        self::assertSame(['passing' => 'true', 'ns' => 'app', 'dc' => 'dc2'], $request['query']);
        self::assertSame('secret', $request['headers']['X-Consul-Token']);
    }

    public function testConsulRegistrationAndMaintenance(): void
    {
        $r = self::consul();
        self::assertTrue($r->join('a/b', '127.0.0.1', 80, ['weight' => 200, 'check' => ['TTL' => '10s']]));
        $data = json_decode($r->requests[0]['body'], true);
        self::assertSame(['TTL' => '10s'], $data['Check']);
        self::assertSame(200, $data['Weights']['Passing']);
        self::assertTrue($r->enableMaintenanceMode('a/b', '127.0.0.1', 80));
        self::assertTrue($r->enableMaintenanceMode('a/b', '127.0.0.1', 80, false));
        self::assertSame('true', $r->requests[1]['query']['enable']);
        self::assertSame('false', $r->requests[2]['query']['enable']);
        self::assertStringContainsString('a%2Fb_127.0.0.1%3A80', $r->requests[1]['path']);
        self::assertTrue($r->leave('a/b', '127.0.0.1', 80));
    }

    public function testNacosUsesTheSameScopeForEveryOperation(): void
    {
        $r       = self::nacos()->withNamespace('tenant')->withGroup('group')->withCluster('blue')->withAccessToken('secret');
        $r->body = 'ok';
        self::assertTrue($r->join('name/?', '::1', 8080, ['weight' => 0.25]));
        self::assertTrue($r->leave('name/?', '::1', 8080));
        $r->body = '{"clientBeatInterval":2500,"code":10200}';
        self::assertSame(2.5, $r->heartbeat('name/?', '::1', 8080, ['weight' => 0.25]));
        $r->body = '{"hosts":[]}';
        self::assertNull($r->getCluster('name/?'));
        foreach ($r->requests as $request) {
            self::assertSame('tenant', $request['query']['namespaceId']);
            self::assertSame('group', $request['query']['groupName']);
            self::assertSame('secret', $request['query']['accessToken']);
            self::assertSame('swoole_service_name/?', $request['query']['serviceName']);
        }
        $beat = json_decode($r->requests[2]['query']['beat'], true);
        self::assertSame('group@@swoole_service_name/?', $beat['serviceName']);
        self::assertSame('blue', $beat['cluster']);
        self::assertSame(0.25, $beat['weight']);
        self::assertSame('true', $r->requests[3]['query']['healthyOnly']);
    }

    public function testNacosSkipsUnhealthyDisabledAndZeroWeightInstances(): void
    {
        $r       = self::nacos();
        $node    = ['ip' => '127.0.0.1', 'port' => 80, 'healthy' => true, 'enabled' => true, 'weight' => 0.25];
        $r->body = json_encode(['hosts' => [$node,
            array_replace($node, ['ip' => '127.0.0.2', 'healthy' => false]),
            array_replace($node, ['ip' => '127.0.0.3', 'enabled' => false]),
            array_replace($node, ['ip' => '127.0.0.4', 'weight' => 0]),
        ]]);
        self::assertSame(0.25, $r->getCluster('test')->pop()['weight']);
        self::assertCount(1, $r->getCluster('test')->getNodes());
    }

    /** @dataProvider malformedResponses */
    public function testMalformedResponses(string $backend, string $body): void
    {
        $r       = $backend === 'consul' ? self::consul() : self::nacos();
        $r->body = $body;
        $this->expectException(Exception::class);
        $r->getCluster('test');
    }

    public static function malformedResponses(): array
    {
        return [['consul', 'broken'], ['consul', 'null'], ['consul', '{"error":"oops"}'],
            ['consul', '[{}]'], ['nacos', '{}'], ['nacos', '{"hosts":{}}'],
            ['nacos', '{"hosts":[{"healthy":true,"enabled":true}]}']];
    }

    public function testUnfilteredRegistryOutageFallsBackToDns(): void
    {
        $resolver         = self::consul();
        $resolver->status = 503;
        $resolver->body   = 'unavailable';
        self::assertNull($resolver->lookup('localhost'));
    }

    public function testBackendErrorsAreNotMissingServices(): void
    {
        $r         = self::consul();
        $r->status = 403;
        $r->body   = 'permission denied';
        $this->expectException(Exception::class);
        $this->expectExceptionCode(403);
        $r->withFilter(fn () => true)->lookup('test');
    }

    public function testScopeCannotBeChangedOnlyForRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::nacos()->join('test', '127.0.0.1', 80, ['namespaceId' => 'different']);
    }

    private static function consul(): Consul
    {
        return new class('http://127.0.0.1') extends Consul {
            use RecordingRequests;
        };
    }

    private static function nacos(): Nacos
    {
        return new class('http://127.0.0.1') extends Nacos {
            use RecordingRequests;
        };
    }
}
