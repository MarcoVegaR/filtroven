<?php

namespace Tests\Support\Wix\Prototype;

use Closure;
use RuntimeException;

/** Prototype measurement unit: reports hashes/counters, NOT provider evidence or a returned document. */
final class SupervisedReceiveProbe
{
    public function run(NativeLab $lab, string $path, LookupBudgetProbe $budget, int $unitDeadline, ?Closure $cancelled = null): array
    {
        $notStarted = ['reason' => 'cancelled', 'pid' => null, 'reaped' => false, 'pipes_closed' => true, 'message' => null,
            'native_started' => false, 'native_completed' => false, 'native_cpu_ticks' => 0, 'native_start_cpu_ticks' => null,
            'byte_accounting' => 'not_started', 'dns_queries' => 0];
        if ($cancelled !== null && $cancelled($notStarted)) {
            return $notStarted;
        }
        if (! NativeTransportProbe::supported() || (new SupervisedPhpTask)->preflight()['problems'] !== []) {
            return array_replace($notStarted, ['reason' => 'invalid_configuration']);
        }
        try {
            $budget->begin();
        } catch (RuntimeException $failure) {
            if ($failure->getMessage() !== 'budget_exhausted' || $budget->active) {
                throw $failure;
            }

            return array_replace($notStarted, ['reason' => 'deadline']);
        }
        $offeredCap = min($budget->maxResponse, $budget->maxTotal - $budget->offered);
        $resolver = $lab->resolver();
        try {
            try {
                $token = PinTokenProbe::synthetic($lab, $path, min($budget->connectDeadline, $unitDeadline - 100000000), 'supervised', 0, $resolver);
            } catch (RuntimeException $failure) {
                $cause = $failure->getMessage();
                if (! in_array($cause, ['dns_deadline_or_cancel', 'unsafe_dns_address', 'cname_loop', 'inconsistent_dns',
                    'empty_or_expired_dns', 'cname_limit', 'dns_socket', 'unbounded_dns_socket', 'dns_send',
                    'invalid_dns_datagram', 'short_dns', 'invalid_dns_response', 'short_dns_record', 'invalid_dns_record',
                    'unexpected_address_owner', 'invalid_cname', 'invalid_address_length', 'trailing_dns', 'dns_name_loop',
                    'short_dns_pointer', 'forward_dns_pointer', 'invalid_dns_label', 'dns_name_limit', 'dns_name_steps', 'invalid_dns_name'], true)) {
                    throw $failure;
                }

                return array_replace($notStarted, ['reason' => $cause === 'dns_deadline_or_cancel' ? 'deadline' : 'transport_error',
                    'dns_queries' => $resolver->queries]);
            }
            $destination = $token->consume($token->destination['url'], 'supervised', 0);
            $config = ['ca' => $lab->directory.'/cert.pem', 'destination' => $destination,
                'global_deadline' => $budget->deadline, 'request_deadline' => min($budget->requestDeadline, $unitDeadline - 100000000),
                'connect_deadline' => min($budget->connectDeadline, $unitDeadline - 100000000), 'expires' => $token->resolution['expires'],
                'max_response' => $budget->maxResponse, 'max_total' => max(1, $budget->maxTotal - $budget->offered)];
            $report = (new SupervisedPhpTask)->run(['operation' => 'native_receive', 'payload' => json_encode($config, JSON_THROW_ON_ERROR), 'units' => 1],
                min($unitDeadline, $budget->deadline - 250000000), $cancelled);
            $network = $report['message']['network_report'] ?? null;
            $report['dns_queries'] = $resolver->queries;
            $report['byte_accounting'] = $network === null ? 'conservative_upper_bound_after_lost_worker' : 'observed_callbacks';
            $budget->offered += $network['offered'] ?? ($offeredCap + 16384);
            $budget->retained += $network['retained'] ?? 0;
            $budget->encodedOffered += $network['encoded_callback_offered'] ?? ($budget->maxResponse + 65536 + 16384);
            $budget->headerOffered += $network['header_offered'] ?? (32768 + 102400);
            if ($network !== null && ! $network['ok']) {
                $report['reason'] = $network['reason'] === 'budget_exhausted' ? 'deadline' : 'transport_error';
            }

            return $report;
        } finally {
            $budget->finish();
        }
    }
}
