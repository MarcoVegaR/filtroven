<?php

namespace App\Support\Wix;

use App\Support\Wix\Data\CoverageSections;
use App\Support\Wix\Enums\DiagnosticCode;
use App\Support\Wix\Profiles\SurfaceInventory;
use App\Support\Wix\Runtime\RuntimeCapabilities;

final class Preflight
{
    /** Input syntax, then disablement, then limits: no network or process creation. */
    public function validate(string $literal, bool $enabled, array $limits): LookupLimits
    {
        if ($literal === '' || trim($literal) !== $literal || preg_match('/[\x00-\x1f\x7f]/', $literal)) {
            throw new OperationalFailure(DiagnosticCode::InvalidInput, 'preflight');
        }
        if (! $enabled) {
            throw new OperationalFailure(DiagnosticCode::Disabled, 'preflight');
        }
        $validated = LookupLimits::fromArray($limits);
        if (strlen($literal) > $validated->inputMaxBytes) {
            throw new OperationalFailure(DiagnosticCode::InvalidInput, 'preflight');
        }
        $runtime = new RuntimeCapabilities;
        $runtime->assertAvailable($runtime->collect());
        foreach (['es-co', 'en-ap'] as $profile) {
            $inventory = new SurfaceInventory($profile);
            foreach (CoverageSections::NAMES as $section) {
                $inventory->surfaces($section);
            }
        }

        return $validated;
    }
}
