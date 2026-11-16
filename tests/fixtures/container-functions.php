<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/functions.php';

/**
 * Replace procfs/cgroup reads in this child process only.
 *
 * @internal
 */
class ContainerFiles
{
    public $context;

    public static array $files = [];

    private string $content = '';

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        if (!isset(self::$files[$path])) {
            return false;
        }
        $this->content = self::$files[$path];
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->content, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->content);
    }

    public function stream_stat(): array
    {
        return ['size' => strlen($this->content), 'mode' => 0100444];
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return isset(self::$files[$path]) ? ['size' => strlen(self::$files[$path]), 'mode' => 0100444] : false;
    }
}

$input                 = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
ContainerFiles::$files = $input['files'];
putenv('SWOOLE_CPU_NUM=' . ($input['override'] ?? ''));
stream_wrapper_unregister('file');
stream_wrapper_register('file', ContainerFiles::class);
try {
    echo json_encode([
        'container' => swoole_is_in_container(),
        'cpus'      => swoole_container_cpu_num(),
        'host_cpus' => swoole_cpu_num(),
    ], JSON_THROW_ON_ERROR);
} finally {
    stream_wrapper_restore('file');
}
