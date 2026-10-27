<?php

namespace App\Support\Wix;

use App\Support\Wix\Data\ProductIdentity;
use App\Support\Wix\Data\ProductSource;
use App\Support\Wix\Enums\DiagnosticCode;

/** Parent-owned identity can be invalidated after confirmation; historical evidence remains append-only. */
final class IdentityLedger
{
    private ?ProductIdentity $identity = null;

    private ?ProductSource $source = null;

    private bool $invalidated = false;

    public function confirm(ProductIdentity $identity, ProductSource $source): void
    {
        if ($this->identity !== null || $this->invalidated) {
            throw new \LogicException('Identity cannot be reconfirmed');
        }
        $this->identity = $identity;
        $this->source = $source;
    }

    /** Only identifiers with the same demonstrated semantic name are comparable. */
    public function compare(string $name, string $value, LookupContext $context, string $reference): void
    {
        $expected = match ($name) {
            'brand' => $this->identity?->brand,
            'sales_designation' => $this->identity?->code,
            default => null,
        };
        foreach ($this->identity?->identifiers ?? [] as $identifier) {
            if ($identifier->name === $name) {
                $expected = $identifier->value;
            }
        }
        if ($expected !== null && $value !== $expected) {
            $this->identity = null;
            $this->source = null;
            $this->invalidated = true;
            $evidence = $context->evidence->all()[$reference] ?? throw new \LogicException('Unknown contradiction evidence');
            $context->diagnose((new OperationalFailure(DiagnosticCode::IdentityMismatch, 'identity', $evidence->attempt_id, 'identity', [$reference]))->diagnostic());
        }
    }

    public function identity(): ?ProductIdentity
    {
        return $this->identity;
    }

    public function source(): ?ProductSource
    {
        return $this->source;
    }
}
