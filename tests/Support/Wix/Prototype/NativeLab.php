<?php

namespace Tests\Support\Wix\Prototype;

use RuntimeException;

/** Own bounded DNS/TLS fixture process. No application or public network server. */
final class NativeLab
{
    private mixed $process = null;

    private array $pipes = [];

    public array $ready = [];

    public string $directory;

    public function __construct(string $scenario = 'loopback')
    {
        if ((new SupervisedPhpTask)->preflight()['problems'] !== [] || ! NativeTransportProbe::supported()) {
            throw new RuntimeException('unsupported_lab_runtime');
        }
        $this->directory = sys_get_temp_dir().'/wix-lab-'.bin2hex(random_bytes(8));
        if (! mkdir($this->directory, 0700)) {
            throw new RuntimeException('lab_directory');
        }
        try {
            $name = in_array($scenario, ['wrong_name', 'public_wrong_name'], true) ? 'wrong.test' : 'runtime.test';
            $config = "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:".$name."\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n";
            file_put_contents($this->directory.'/openssl.cnf', $config);
            $options = ['config' => $this->directory.'/openssl.cnf', 'digest_alg' => 'sha256', 'private_key_bits' => 2048];
            $key = openssl_pkey_new($options);
            $csr = openssl_csr_new(['commonName' => 'runtime.test'], $key, $options);
            $cert = openssl_csr_sign($csr, null, $key, 1, $options + ['x509_extensions' => 'v3']);
            if ($cert === false || ! openssl_pkey_export($key, $keyPem) || ! openssl_x509_export($cert, $certPem)) {
                throw new RuntimeException('lab_certificate');
            }
            file_put_contents($this->directory.'/key.pem', $keyPem);
            file_put_contents($this->directory.'/cert.pem', $certPem);
            set_error_handler(static fn (int $severity): bool => $severity === E_WARNING, E_WARNING);
            try {
                $this->process = proc_open([PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'ffi.enable=0', '-d', 'allow_url_fopen=0',
                    '-d', 'disable_functions=proc_open,popen,exec,shell_exec,system,passthru,pcntl_fork,pcntl_exec,dl',
                    __DIR__.'/security-lab.php', $this->directory, $scenario, (string) (hrtime(true) + 15000000000)],
                    [0 => ['socket'], 1 => ['socket'], 2 => ['socket']], $this->pipes, __DIR__,
                    ['PATH' => '/usr/bin:/bin', 'PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR') ?: PHP_CONFIG_FILE_SCAN_DIR]);
            } finally {
                restore_error_handler();
            }
            if (! is_resource($this->process)) {
                throw new RuntimeException('lab_launch');
            }
            foreach ($this->pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
            $reader = new ChildTaskProtocol;
            $deadline = hrtime(true) + 3000000000;
            while (hrtime(true) < $deadline) {
                $read = [$this->pipes[1]];
                $write = $except = [];
                stream_select($read, $write, $except, 0, 10000);
                if ($read !== []) {
                    $bytes = fread($this->pipes[1], 4096);
                    $reader->accept($bytes, $deadline);
                    try {
                        $this->ready = $reader->finish();

                        return;
                    } catch (RuntimeException) {
                        if (feof($this->pipes[1])) {
                            throw new RuntimeException('lab_eof:'.stream_get_contents($this->pipes[2], 1024));
                        }
                    }
                }
            }
            throw new RuntimeException('lab_start_deadline');
        } catch (\Throwable $failure) {
            $this->close();
            throw $failure;
        }
    }

    public function resolver(): BoundedResolverProbe
    {
        return new BoundedResolverProbe('127.0.0.1', $this->ready['dns_port']);
    }

    /** @return list<array<string,mixed>> */
    public function events(): array
    {
        $path = $this->directory.'/events';
        $bytes = is_file($path) ? file_get_contents($path, false, null, 0, 65537) : '';
        if (strlen($bytes) > 65536) {
            throw new RuntimeException('lab_log_limit');
        }

        return array_map(static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), array_filter(explode("\n", $bytes)));
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process, 15);
                $deadline = hrtime(true) + 100000000;
                do {
                    usleep(1000);
                    $status = proc_get_status($this->process);
                } while ($status['running'] && hrtime(true) < $deadline);
                if ($status['running']) {
                    proc_terminate($this->process, 9);
                    $deadline = hrtime(true) + 100000000;
                    do {
                        usleep(1000);
                        $status = proc_get_status($this->process);
                    } while ($status['running'] && hrtime(true) < $deadline);
                }
            }
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            $this->pipes = [];
            if ($status['running']) {
                throw new RuntimeException('lab_not_reapable');
            }
            proc_close($this->process);
            $this->process = null;
        }
        foreach (['openssl.cnf', 'key.pem', 'cert.pem', 'events', 'admission'] as $file) {
            if (is_file($this->directory.'/'.$file)) {
                unlink($this->directory.'/'.$file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }
}
