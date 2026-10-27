<?php

namespace Tests\Support\Wix\Prototype;

use RuntimeException;

/** Composes the existing synthetic identity ledger with actual admission, DNS/TLS/decoder and native CPU units. */
final class SecurityCheckpointProbe
{
    public function run(NativeLab $lab, int $deadline, bool $identityConfirmed = true, string $topology = 'linux-cli-one-host-shared-inode'): array
    {
        $started = hrtime(true);
        if (! NativeTransportProbe::supported() || (new SupervisedPhpTask)->preflight()['problems'] !== []) {
            return ['dto' => ParentResultProbe::snapshot('not_attempted'), 'supported_topology' => false, 'production_supported' => false, 'network_requests' => 0];
        }
        $lock = $lab->directory.'/admission';
        if (! is_file($lock)) {
            file_put_contents($lock, '');
        }
        $stat = stat($lock);
        $admission = new AdmissionProbe($lock, $stat['dev'], $stat['ino'], $topology);
        $budget = new LookupBudgetProbe($deadline, maxResponse: 65536);
        try {
            if (! $admission->acquire($budget)) {
                throw new RuntimeException('budget_exhausted');
            }
        } catch (RuntimeException $failure) {
            if ($failure->getMessage() !== 'invalid_configuration') {
                throw $failure;
            }

            return ['dto' => ParentResultProbe::snapshot('not_attempted'), 'supported_topology' => false, 'production_supported' => false, 'network_requests' => 0];
        }
        $utc = gmdate('Y-m-d\TH:i:s\Z');
        try {
            /** Existing parent-owned synthetic search/detail facts already spent two sends. */
            $priorRequests = $identityConfirmed ? 2 : 1;
            $budget->requests = $priorRequests;
            $result = (new ParentResultProbe)->run(['media' => 'native_receive', 'documents' => 'echo', 'dimensions' => 'native_dom'], $deadline,
                unitTimeoutMs: 1500, identityConfirmed: $identityConfirmed,
                unitExecutor: static function (string $section, string $operation, int $unitDeadline, ?\Closure $cancel) use ($lab, $budget): array {
                    if ($section === 'media') {
                        return (new SupervisedReceiveProbe)->run($lab, '/gzip', $budget, $unitDeadline, $cancel);
                    }
                    $budget->visit(180010);

                    return (new SupervisedPhpTask)->run(['operation' => $operation, 'payload' => 'SYNTHETIC fixed bounded DOM', 'units' => 1], $unitDeadline, $cancel);
                });
            $finished = gmdate('Y-m-d\TH:i:s\Z');
            $dto = $result['dto'];
            $dto['scope']['consulted_profiles'][] = 'synthetic-loopback';
            $dto['meta']['profile_versions']['synthetic-loopback'] = DestinationPolicyProbe::VERSION;
            $used = ['elapsed_ms' => (int) ceil((hrtime(true) - $started) / 1000000), 'requests' => $budget->requests - $priorRequests,
                'redirects' => $budget->redirects, 'retries' => $budget->retries, 'bytes' => $budget->offered, 'nodes' => $budget->nodes];
            $dto['attempts'][] = ['attempt_id' => 'a-native-synthetic', 'profile' => 'synthetic-loopback', 'stage' => 'synthetic_native_receive',
                'pages' => [], 'requests' => $used['requests'], 'outcome' => $result['reports']['media']['reason'], 'evidence_refs' => [],
                'started_at' => $utc, 'finished_at' => $finished, 'budget_used' => $used];
            $dto['meta']['budget_used'] = array_replace($used, ['requests' => $budget->requests]);
            $dto['meta']['returned_at'] = $finished;
            ContractWitness::assert($dto);
            $units = $bytes = 0;
            ContractWitness::admit($dto, $units, $bytes);
            $json = json_encode($dto, JSON_THROW_ON_ERROR);
            ContractWitness::assert(json_decode($json, true, 20, JSON_THROW_ON_ERROR));
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('checkpoint_missed_deadline');
            }

            return $result + ['checkpoint_dto' => $dto, 'supported_topology' => true, 'production_supported' => false,
                'network_requests' => $budget->requests - $priorRequests, 'decoded_offered' => $budget->offered, 'decoded_retained' => $budget->retained,
                'total_elapsed_ms' => (hrtime(true) - $started) / 1000000, 'original_remaining_ms' => ($deadline - hrtime(true)) / 1000000];
        } finally {
            $admission->release();
        }
    }
}
