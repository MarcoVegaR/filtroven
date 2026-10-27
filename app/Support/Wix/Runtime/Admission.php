<?php

namespace App\Support\Wix\Runtime;

use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\LookupBudget;
use App\Support\Wix\OperationalFailure;

/** Advisory lock for the explicitly selected one-host, shared-inode Linux CLI topology only. */
final class Admission
{
    private mixed $handle = null;

    private ?LookupBudget $owner = null;

    private int $depth = 0;

    public function __construct(private readonly string $path, private readonly int $device, private readonly int $inode, private readonly string $topology) {}

    public function acquire(LookupBudget $budget): void
    {
        if ($this->handle !== null && $this->owner === $budget) {
            $budget->check('admission');
            $this->depth++;

            return;
        }
        if ($this->handle !== null || $this->topology !== 'linux-cli-one-host-shared-inode' || PHP_SAPI !== 'cli'
            || ! (new SupervisedPhpTask)->supported() || is_link($this->path) || ! is_file($this->path)) {
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'admission');
        }
        $budget->check('admission');
        set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
        try {
            $this->handle = fopen($this->path, 'r+');
        } finally {
            restore_error_handler();
        }
        if (! is_resource($this->handle)) {
            $this->handle = null;
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'admission');
        }
        $acquired = false;
        try {
            $stat = fstat($this->handle);
            if ($stat['dev'] !== $this->device || $stat['ino'] !== $this->inode || $stat['nlink'] !== 1 || $stat['size'] !== 0 || ($stat['mode'] & 0170000) !== 0100000) {
                throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'admission');
            }
            while (true) {
                $budget->check('admission');
                if (flock($this->handle, LOCK_EX | LOCK_NB)) {
                    $acquired = true;
                    $this->owner = $budget;
                    $this->depth = 1;

                    return;
                }
                usleep(1000);
            }
        } finally {
            if (! $acquired) {
                $this->release();
            }
        }
    }

    public function release(): void
    {
        if ($this->depth > 1) {
            $this->depth--;

            return;
        }
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
        $this->owner = null;
        $this->depth = 0;
    }
}
