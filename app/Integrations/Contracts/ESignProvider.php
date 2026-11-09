<?php

namespace App\Integrations\Contracts;

use App\Models\OdrSettlement;
use App\Models\User;

/** Step 21. Asks a signer to sign with Aadhaar eSign or a DSC. */
interface ESignProvider
{
    /** @return string the provider's request reference */
    public function requestSignature(OdrSettlement $settlement, User $signer): string;
}
