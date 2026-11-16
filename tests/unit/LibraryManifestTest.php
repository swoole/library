<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * @internal
 * @coversNothing
 */
class LibraryManifestTest extends TestCase
{
    public function testComposerLoadsEveryFileThatPsr4CannotLoadInManifestOrder(): void
    {
        $root     = dirname(__DIR__, 2);
        $manifest = require $root . '/src/__init__.php';
        $expected = [];
        foreach ($manifest['files'] as $file) {
            $class        = 'Swoole\\' . str_replace('/', '\\', substr($file, 5, -4));
            $declarations = self::declarations($root . '/src/' . $file);
            if (!str_starts_with($file, 'core/') || !isset($declarations[$class])) {
                $expected[] = $file;
            }
        }
        preg_match_all("~require_once __DIR__ \\. '/([^']+)';~", file_get_contents($root . '/src/vendor_init.php'), $matches);
        self::assertSame($expected, $matches[1], 'Non-class files, including core function files, must load in manifest order');
    }

    public function testEmbeddedClassAndTraitDependenciesPrecedeTheirUsers(): void
    {
        $root      = dirname(__DIR__, 2);
        $manifest  = require $root . '/src/__init__.php';
        $classes   = [];
        $positions = [];
        foreach ($manifest['files'] as $position => $file) {
            foreach (self::declarations($root . '/src/' . $file) as $name => $declaration) {
                self::assertArrayNotHasKey($name, $classes, 'Duplicate declaration: ' . $name);
                $classes[$name]   = $declaration;
                $positions[$name] = $position;
            }
        }
        foreach ($classes as $name => $declaration) {
            $dependencies = [];
            if ($declaration instanceof Node\Stmt\Class_) {
                $dependencies = $declaration->implements;
                if ($declaration->extends !== null) {
                    $dependencies[] = $declaration->extends;
                }
            } elseif ($declaration instanceof Node\Stmt\Interface_) {
                $dependencies = $declaration->extends;
            } elseif ($declaration instanceof Node\Stmt\Enum_) {
                $dependencies = $declaration->implements;
            }
            foreach ($declaration->stmts as $statement) {
                if ($statement instanceof Node\Stmt\TraitUse) {
                    array_push($dependencies, ...$statement->traits);
                }
            }
            foreach ($dependencies as $dependency) {
                $dependency = $dependency->toString();
                if (isset($positions[$dependency])) {
                    self::assertLessThanOrEqual($positions[$name], $positions[$dependency], $name . ' requires ' . $dependency);
                }
            }
        }
    }

    public function testSerialSuiteMatchesConcurrentFileExclusions(): void
    {
        $root = dirname(__DIR__, 2);
        $xml  = new \DOMDocument();
        self::assertTrue($xml->load($root . '/phpunit.xml.dist'));
        $xpath  = new \DOMXPath($xml);
        $serial = $excluded = [];
        foreach ($xpath->query('/phpunit/testsuites/testsuite[@name="serial"]/file') as $node) {
            $file = trim($node->textContent);
            self::assertFileExists($root . '/' . $file);
            $serial[] = $file;
        }
        foreach ($xpath->query('/phpunit/testsuites/testsuite[@name="concurrent"]/exclude') as $node) {
            $file = trim($node->textContent);
            if ($file === 'tests/unit/Thread') {
                continue;
            } // Dedicated ZTS job.
            self::assertFileExists($root . '/' . $file);
            $excluded[] = $file;
        }
        self::assertCount(count($serial), array_unique($serial));
        self::assertCount(count($excluded), array_unique($excluded));
        sort($serial);
        sort($excluded);
        self::assertSame($serial, $excluded, 'A serial test must be excluded from concurrent and included in serial exactly once');
    }

    /** @return array<string, Node\Stmt\ClassLike> */
    private static function declarations(string $file): array
    {
        $parser    = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser(new NameResolver());
        $nodes     = $traverser->traverse($parser->parse(file_get_contents($file)));
        $classes   = [];
        foreach ((new NodeFinder())->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $node) {
            if (isset($node->namespacedName)) {
                $classes[$node->namespacedName->toString()] = $node;
            }
        }
        return $classes;
    }
}
