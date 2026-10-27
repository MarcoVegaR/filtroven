<?php

namespace App\Support\Wix\Http;

use App\Support\Wix\Contracts\WixTransport;
use App\Support\Wix\Data\CapturedResponse;
use App\Support\Wix\Data\Diagnostic;
use App\Support\Wix\Data\NameValue;
use App\Support\Wix\Data\RequestPlan;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Enums\EvidenceSurface;
use App\Support\Wix\Enums\Severity;
use App\Support\Wix\LookupContext;
use App\Support\Wix\OperationalFailure;
use App\Support\Wix\Profiles\ProfileRegistry;
use App\Support\Wix\Runtime\Admission;
use App\Support\Wix\Runtime\SupervisedPhpTask;

final class WixHttpTransport implements WixTransport
{
    public string $lastByteAccounting = 'not_started';

    /** @var list<array<string,mixed>> Internal bounded callback reports; not part of the public DTO. */
    public array $lastNativeReports = [];

    public function __construct(private readonly DestinationGuard $guard, private readonly SupervisedPhpTask $supervisor, private readonly Admission $admission, private readonly string $caFile) {}

    /** One shared-inode lease spans parsing and finalization as well as all transmissions of a public lookup. */
    public function withinLookup(LookupContext $context, \Closure $work): mixed
    {
        $this->admission->acquire($context->budget);
        try {
            return $work();
        } finally {
            $this->admission->release();
        }
    }

    public function send(RequestPlan $request, LookupContext $context): CapturedResponse
    {
        $this->lastByteAccounting = 'not_started';
        $this->lastNativeReports = [];
        if (! NativeExchange::supported() || ! DestinationGuard::supported() || ! $this->supervisor->supported() || ! is_file($this->caFile)) {
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'transport');
        }
        $this->guard->validate($request->url, $request->profile);
        $profile = (new ProfileRegistry)->get($request->profile);
        $graphql = $request->surface === EvidenceSurface::GraphqlSearch;
        $path = parse_url($request->url, PHP_URL_PATH);
        if ($request->headers != $profile->headers($graphql)
            || ($graphql && $path !== parse_url($profile->endpoint, PHP_URL_PATH))
            || ($request->surface === EvidenceSurface::Html && ! str_starts_with($path, '/'.$request->profile.'/catalog/results/product.html/'))
            || ($request->surface === EvidenceSurface::AssetMetadata && $path !== '/bin/assets.json')) {
            throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'headers');
        }
        $headers = [];
        foreach ($request->headers as $header) {
            if (! in_array($header->name, ['User-Agent', 'Accept', 'Accept-Encoding', 'Store', 'Content-Type'], true)) {
                throw new OperationalFailure(DiagnosticCode::InvalidConfiguration, 'headers');
            }
            $headers[] = $header->name.': '.$header->value;
        }
        $headers[] = 'Connection: close';
        $budget = $context->budget;
        $this->admission->acquire($budget);
        try {
            $budget->begin();
            try {
                for ($refresh = 0; ; $refresh++) {
                    $destination = $this->guard->prepare($request, $context);
                    $budget->check('dns');
                    $maximum = min(2097152, $budget->limits->maxDecodedBytesPerResponse, $budget->limits->maxDecodedBytesTotal - $budget->snapshot()->bytes);
                    $body = '';
                    $report = null;
                    $started = false;
                    $workerFailure = null;
                    try {
                        $started = true;
                        $this->supervisor->run('receive', ['destination' => $destination, 'ca' => $this->caFile, 'maximum' => $maximum, 'headers' => $headers,
                            'connect_deadline' => $budget->connectDeadline, 'request_deadline' => $budget->requestDeadline], '',
                            min($budget->requestDeadline + 100000000, $budget->deadline - $budget->limits->finalizationReserveMs * 1000000),
                            $maximum + intdiv($maximum, 16000) * 10 + 32768,
                            static function (string $frame) use (&$body, &$report, $budget, $maximum): void {
                                if ($report !== null) {
                                    throw new OperationalFailure(DiagnosticCode::SchemaError, 'receive');
                                }
                                if ($frame[0] === 'D') {
                                    $chunk = substr($frame, 1);
                                    $budget->receive(strlen($chunk));
                                    if (strlen($chunk) > $maximum - strlen($body)) {
                                        throw new OperationalFailure(DiagnosticCode::BudgetExhausted, 'receive');
                                    }
                                    $body .= $chunk;
                                } elseif ($frame[0] === 'R') {
                                    $report = json_decode(substr($frame, 1), true, 8, JSON_THROW_ON_ERROR);
                                } else {
                                    throw new OperationalFailure(DiagnosticCode::SchemaError, 'receive');
                                }
                            }, static function () use ($budget): bool {
                                $budget->check('receive');

                                return false;
                            });
                    } catch (OperationalFailure $failure) {
                        $workerFailure = $failure;
                        throw $failure;
                    } finally {
                        if ($started) {
                            $this->lastByteAccounting = $report === null ? 'conservative_upper_bound_after_lost_worker' : 'observed_callbacks';
                            $offered = $report['offered'] ?? ($maximum + 16384);
                            try {
                                $budget->receive(max(0, $offered - strlen($body)));
                            } catch (OperationalFailure $debitFailure) {
                                if ($workerFailure === null) {
                                    throw $debitFailure;
                                }
                            }
                        }
                    }
                    if (is_array($report)) {
                        $this->lastNativeReports[] = array_intersect_key($report, array_flip(['ok', 'status', 'offered', 'retained', 'encoded', 'header_bytes', 'prerequests', 'connections', 'errno', 'request_bytes', 'phase', 'cause', 'reason']));
                    }
                    if (! is_array($report) || ! $report['ok'] || $report['retained'] !== strlen($body) || $report['prerequests'] > 1 || $report['connections'] > 1) {
                        if ($refresh === 0 && is_array($report) && ! $report['ok']
                            && in_array($report['cause'] ?? '', ['pin_expired_before_connect', 'pin_expired'], true)
                            && $report['request_bytes'] === 0 && $report['status'] === 0 && $report['header_bytes'] === 0 && $report['offered'] === 0
                            && $report['prerequests'] <= 1 && $report['connections'] <= 1 && $body === '') {
                            $budget->refreshPin();

                            continue;
                        }
                        $code = DiagnosticCode::tryFrom($report['reason'] ?? '') ?? DiagnosticCode::TransportError;
                        $cause = $report['cause'] ?? '';
                        throw new OperationalFailure($code, $cause === '' ? 'receive' : 'receive.'.$cause);
                    }

                    if ($refresh === 1) {
                        $context->diagnose(new Diagnostic(DiagnosticCode::TransportError, 'dns_refresh', $request->attemptId, Severity::Warning, true, 'Expired pin before HTTP; one fresh DNS/TLS admission within the original deadlines.', null, []));
                    }

                    return new CapturedResponse($request, $report['status'], array_map(static fn (string $key, string $value): NameValue => new NameValue($key, $value), array_keys($report['headers']), array_values($report['headers'])), $body, null, gmdate('Y-m-d\TH:i:s\Z'));
                }
            } finally {
                $budget->finish();
            }
        } finally {
            $this->admission->release();
        }
    }
}
