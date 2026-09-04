<?php

declare(strict_types=1);

namespace Spezitest\Admin\Validation;

final readonly class DrinkInput
{
    public function __construct(
        public string $name,
        public string $lifecycleStatus,
        public ?string $manufacturer,
        public ?string $originLocation,
        public ?string $originRegion,
        public ?string $notes,
    ) {
    }
}
