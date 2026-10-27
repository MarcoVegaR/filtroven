<?php

namespace App\Support\Wix\Profiles;

final readonly class ProfileRegistry
{
    public function get(string $id): ProfileDefinition
    {
        return match ($id) {
            'es-co' => new EsCoProfile,
            'en-ap' => new EnApProfile,
            default => throw new \LogicException('Unknown closed profile'),
        };
    }
}
