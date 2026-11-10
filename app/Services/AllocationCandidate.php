<?php

namespace App\Services;

/** One firm member as shown in the allocation matrix (PRD 2.4). */
final class AllocationCandidate
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $areas
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $roles,
        public readonly array $areas,
        public readonly bool $matches_area,
        public readonly string $availability,
        public readonly ?string $unavailable_until,
        public readonly int $active_matters,
        public readonly ?int $max_active_matters,
        public readonly bool $at_capacity,
    ) {}
}
