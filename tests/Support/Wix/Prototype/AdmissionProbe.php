<?php

namespace Tests\Support\Wix\Prototype;

use Closure;
use RuntimeException;

/** One shared inode, one Linux host/namespace, all CLI callers participating. NOT a multi-host production guarantee. */
final class AdmissionProbe
{
    private mixed $handle = null;

    public function __construct(private readonly string $path, private readonly int $device, private readonly int $inode,
        private readonly string $topology = 'linux-cli-one-host-shared-inode') {}

    public function acquire(LookupBudgetProbe $budget, ?Closure $cancelled = null): bool
    {
        if (is_resource($this->handle)) {
            throw new RuntimeException('admission_already_held');
        }
        $memory = trim(file_get_contents('/sys/fs/cgroup/memory.max', false, null, 0, 32));
        if ($this->topology !== 'linux-cli-one-host-shared-inode' || PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli'
            || ! ctype_digit($memory) || (int) $memory < 67108864 || (int) $memory > 536870912
            || ! function_exists('flock') || is_link($this->path) || ! is_file($this->path)) {
            throw new RuntimeException('invalid_configuration');
        }
        $budget->check();
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
        try {
            $this->handle = fopen($this->path, 'r+');
        } finally {
            restore_error_handler();
        }
        if ($this->handle === false) {
            throw new RuntimeException('invalid_configuration');
        }
        $acquired = false;
        try {
            $stat = fstat($this->handle);
            if ($stat['dev'] !== $this->device || $stat['ino'] !== $this->inode || $stat['nlink'] !== 1
                || $stat['size'] !== 0 || ($stat['mode'] & 0170000) !== 0100000) {
                $this->release();
                throw new RuntimeException('invalid_configuration');
            }
            while (hrtime(true) < $budget->deadline - 250000000) {
                if ($cancelled !== null && $cancelled()) {
                    $this->release();

                    return false;
                }
                if (flock($this->handle, LOCK_EX | LOCK_NB)) {
                    $acquired = true;

                    return true;
                }
                usleep(1000);
            }
            $this->release();

            return false;
        } finally {
            if (! $acquired) {
                $this->release();
            }
        }
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }
}
