<?php

namespace App\Http\Controllers\Web\Practice;

use App\Http\Controllers\Controller;
use App\Integrations\Contracts\AiDrafting;
use App\Integrations\IntegrationRegistry;
use App\Services\AuditLogWriter;
use App\Support\AccessContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Steps 17 and 18: a first draft or a summary for an advocate to check. Nothing typed here
 * or returned is stored; the audit log records only that it was used, with the lengths.
 * The driver masks identifiers before anything leaves the server.
 */
class AiDraftController extends Controller
{
    public function index(): View
    {
        return view('practice.ai', ['active' => $this->active(), 'result' => null, 'mode' => null]);
    }

    public function draft(Request $request, AiDrafting $ai): View
    {
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:150'],
            'facts' => ['required', 'string', 'max:8000'],
        ]);
        $result = $ai->draft($data['document_type'], ['Facts' => $data['facts']]);
        $this->audit($request, 'draft', mb_strlen($data['facts']));

        return view('practice.ai', ['active' => true, 'result' => $result, 'mode' => 'draft']);
    }

    public function summarise(Request $request, AiDrafting $ai): View
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:30000']]);
        $result = $ai->summarise($data['text']);
        $this->audit($request, 'summary', mb_strlen($data['text']));

        return view('practice.ai', ['active' => true, 'result' => $result, 'mode' => 'summary']);
    }

    private function active(): bool
    {
        return IntegrationRegistry::driverClass('ai', (string) config('integrations.ai.driver')) !== null;
    }

    private function audit(Request $request, string $kind, int $length): void
    {
        app(AuditLogWriter::class)->record('ai.'.$kind.'_requested', 'user', (string) $request->user()?->getAuthIdentifier(), AccessContext::currentTenantId(), null, ['input_length' => $length]);
    }
}
