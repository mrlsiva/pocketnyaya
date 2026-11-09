<?php

namespace App\Integrations;

use RuntimeException;

/** An outside service that has no approved credentials yet. Shown to users as a plain message. */
class IntegrationNotConfigured extends RuntimeException
{
    public function __construct(public readonly string $integration)
    {
        parent::__construct("{$integration} is not set up yet. An administrator must add approved credentials first.");
    }
}
