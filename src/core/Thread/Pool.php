<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Thread;

use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use Swoole\Thread;

/**
 * @since 6.0.0-beta
 */
class Pool
{
    private array $threads = [];

    private string $autoloader = '';

    private string $classDefinitionFile = '';

    private int $threadNum = 0;

    private string $proxyFile;

    private array $arguments = [];

    private Atomic $running;

    private Queue $queue;

    public function __construct(private readonly string $runnableClass, int $threadNum)
    {
        if ($threadNum <= 0) {
            throw new \Exception('threadNum must be greater than 0');
        }
        $this->threadNum = $threadNum;
    }

    public function withArguments(mixed ...$arguments): static
    {
        $this->arguments = $arguments;
        return $this;
    }

    public function withAutoloader(string $autoloader): static
    {
        $this->autoloader = $autoloader;
        return $this;
    }

    public function withClassDefinitionFile(string $classDefinitionFile): static
    {
        $this->classDefinitionFile = $classDefinitionFile;
        return $this;
    }

    /**
     * @throws \ReflectionException
     */
    public function start(): void
    {
        if (empty($this->classDefinitionFile) && class_exists($this->runnableClass, false)) {
            $file = (new \ReflectionClass($this->runnableClass))->getFileName();
            if (!$this->isValidPhpFile($file)) {
                throw new \Exception('class definition file must not contain any expressions.');
            }
            $this->classDefinitionFile = $file;
        } elseif ($this->classDefinitionFile) {
            require_once $this->classDefinitionFile;
        }

        if (!class_exists($this->runnableClass)) {
            throw new \Exception("class `{$this->runnableClass}` not found");
        }

        if (!is_subclass_of($this->runnableClass, Runnable::class)) {
            throw new \Exception("class `{$this->runnableClass}` must implements Thread\\Runnable");
        }

        if (empty($this->autoloader)) {
            $include_files = get_included_files();
            foreach ($include_files as $file) {
                if (str_ends_with($file, 'vendor/autoload.php')) {
                    $this->autoloader = $file;
                    break;
                }
            }
        }

        // Thread requests have their own working directory; resolve paths before launching them.
        if ($this->autoloader !== '') {
            $this->autoloader = realpath($this->autoloader) ?: $this->autoloader;
        }
        if ($this->classDefinitionFile !== '') {
            $this->classDefinitionFile = realpath($this->classDefinitionFile) ?: $this->classDefinitionFile;
        }

        $this->queue     = new Queue();
        $this->running   = new Atomic(1);
        $this->proxyFile = $this->createRunner();

        try {
            for ($index = 0; $index < $this->threadNum; $index++) {
                $this->createThread($index);
            }

            while ($this->running->get()) {
                $index  = $this->queue->pop(-1);
                $thread = $this->threads[$index];
                $thread->join();
                unset($this->threads[$index]);

                $this->createThread($index);
            }
        } finally {
            $this->running->set(0);
            try {
                foreach ($this->threads as $thread) {
                    $thread->join();
                }
            } finally {
                $this->threads = [];
                unlink($this->proxyFile);
            }
        }
    }

    public function shutdown(): void
    {
        $this->running->set(0);
    }

    /**
     * Whether the file has nothing but declarations at its top, so that every thread can load it without running
     * anything. It takes the package nikic/php-parser to tell.
     */
    protected function isValidPhpFile(string $filePath): bool
    {
        if (!class_exists(ParserFactory::class)) {
            throw new \Exception('The file of the class to run cannot be checked without the package nikic/php-parser. Install it, or set the file with withClassDefinitionFile().');
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $code  = file_get_contents($filePath);
            $stmts = $parser->parse($code);
            return $this->containsOnlyDeclarations($stmts ?? []);
        } catch (Error) {
            return false;
        }
    }

    protected function createThread(int $index): void
    {
        if (!$this->running->get()) {
            return;
        }

        $this->threads[$index] = new Thread($this->proxyFile,
            $this->autoloader,
            $this->runnableClass,
            $this->queue,
            $this->classDefinitionFile,
            $this->running,
            $index,
            ...$this->arguments
        );
    }

    private function createRunner(): string
    {
        $script = <<<'PHP'
<?php
$arguments = Swoole\Thread::getArguments();
$autoloader = $arguments[0];
$runnableClass = $arguments[1];
$queue = $arguments[2];
$index = $arguments[5];
// PHP exit() and fatal errors skip finally, but still run request shutdown functions.
register_shutdown_function(static function () use ($queue, $index): void {
    $queue->push($index, Swoole\Thread\Queue::NOTIFY_ONE);
});
$classDefinitionFile = $arguments[3];
$running = $arguments[4];
$threadArguments = array_slice($arguments, 6);
// Preserve the working directory used when runners lived next to the autoloader or class file.
chdir(dirname($autoloader ?: $classDefinitionFile));
if ($autoloader) require_once $autoloader;
if ($classDefinitionFile) require_once $classDefinitionFile;
$runnable = new $runnableClass($running, $index);
$runnable->run($threadArguments);
PHP;

        $file = @tempnam(sys_get_temp_dir(), 'swoole_thread_runner_');
        if ($file === false) {
            throw new \RuntimeException('Failed to create thread runner in the temporary directory.');
        }
        try {
            if (@file_put_contents($file, $script) !== strlen($script)) {
                throw new \RuntimeException("Failed to write thread runner '{$file}'.");
            }
            return $file;
        } catch (\Throwable $exception) {
            unlink($file);
            throw $exception;
        }
    }

    /**
     * @param Stmt[] $statements
     */
    private function containsOnlyDeclarations(array $statements): bool
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_ || $statement instanceof Stmt\Declare_) {
                if (!$this->containsOnlyDeclarations($statement->stmts ?? [])) {
                    return false;
                }
                continue;
            }

            // Class and function bodies are deferred; only inspect containers executed when loading the file.
            if ($statement instanceof Stmt\ClassLike
                || $statement instanceof Stmt\Function_
                || $statement instanceof Stmt\Const_
                || $statement instanceof Stmt\Use_
                || $statement instanceof Stmt\GroupUse
                || $statement instanceof Stmt\Nop) {
                continue;
            }
            return false;
        }
        return true;
    }
}
