<?php

namespace Tests\Support\Wix\Prototype;

use RuntimeException;

/** Only explicit experimental quotas permit following a synthetic hop or retry. Defaults remain zero. */
final class NativeNavigationProbe
{
    public function run(NativeLab $lab, string $path, LookupBudgetProbe $budget): array
    {
        $history = [];
        $visited = [];
        $kind = 'initial';
        $recovered = false;
        while (count($history) < 13) {
            $budget->begin($kind);
            try {
                $token = PinTokenProbe::synthetic($lab, $path, $budget->connectDeadline, 'navigation', count($history));
                $response = (new NativeTransportProbe($lab->directory.'/cert.pem'))->send($token, $budget, 'navigation', count($history));
            } finally {
                $budget->finish();
            }
            $history[] = $response;
            if (! $response['ok']) {
                return ['response' => $response, 'history' => $history, 'recovered' => false];
            }
            if (in_array($response['status'], [301, 302, 303, 307, 308], true) && $budget->maxRedirects > 0) {
                $location = $response['headers']['location'] ?? '';
                if (preg_match('~^/[a-z0-9/-]{0,80}$~D', $location) !== 1 || str_starts_with($location, '//')
                    || isset($visited[$location]) || $location === $path) {
                    throw new RuntimeException('transport_error');
                }
                $visited[$path] = true;
                $path = $location;
                $kind = 'redirect';

                continue;
            }
            if (in_array($response['status'], [429, 503], true) && $budget->maxRetries > 0 && $kind !== 'retry') {
                $retryAfter = $response['headers']['retry-after'] ?? '0';
                if (! ctype_digit($retryAfter) || strlen($retryAfter) > 1) {
                    throw new RuntimeException('budget_exhausted');
                }
                $budget->wait((int) $retryAfter * 1000);
                $kind = 'retry';
                $recovered = true;

                continue;
            }

            return ['response' => $response, 'history' => $history, 'recovered' => $recovered && $response['status'] === 200];
        }
        throw new RuntimeException('budget_exhausted');
    }
}
