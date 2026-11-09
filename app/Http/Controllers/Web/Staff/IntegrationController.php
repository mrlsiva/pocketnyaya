<?php

namespace App\Http\Controllers\Web\Staff;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationRegistry;
use App\Integrations\PiiMasker;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Phase 4 status (no secrets shown) and a preview of the local personal-data masker. */
class IntegrationController extends Controller
{
    public function index(Request $request, IntegrationRegistry $registry, PiiMasker $masker): View
    {
        // Sent by POST, so sample text never appears in URLs or server logs.
        $sample = $request->isMethod('post') ? (string) $request->input('sample', '') : '';
        abort_if(mb_strlen($sample) > 5000, 422);

        return view('staff.admin.integrations', [
            'services' => $registry->status(),
            'sample' => $sample,
            // The preview is computed for display only and never stored or logged.
            'masked' => $sample === '' ? null : $masker->mask($sample),
        ]);
    }
}
