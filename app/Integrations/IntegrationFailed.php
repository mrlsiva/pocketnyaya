<?php

namespace App\Integrations;

use RuntimeException;
use Throwable;

/**
 * An outside service that is set up but refused or failed this request. Users see a plain
 * message; the log gets the provider's reason without credentials or the text that was sent.
 */
class IntegrationFailed extends RuntimeException
{
    public function __construct(public readonly string $integration, string $reason, ?Throwable $previous = null)
    {
        parent::__construct("{$integration} could not complete this request: {$reason}", 0, $previous);
    }
}
