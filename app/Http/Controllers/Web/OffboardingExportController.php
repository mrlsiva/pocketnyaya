<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TenantOffboarding;
use App\Models\User;
use App\Services\OffboardingService;
use App\Services\PracticeExportBuilder;
use App\Support\Isolation;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The named firm recipient downloads their closing practice's export. Nobody else can. */
class OffboardingExportController extends Controller
{
    public function mine(Request $request): View
    {
        return view('offboarding.mine', [
            'closures' => TenantOffboarding::query()->with('tenant:id,name')->where('export_recipient_user_id', $this->user($request)->id)
                ->whereIn('status', ['export_open', 'retention'])->latest('requested_at')->get(),
        ]);
    }

    public function download(Request $request, TenantOffboarding $offboarding, PracticeExportBuilder $builder, OffboardingService $service): BinaryFileResponse
    {
        $user = $this->user($request);
        abort_unless($offboarding->export_recipient_user_id === $user->id, 404);
        abort_unless($offboarding->exportAvailable(), 410, 'This export is no longer available.');

        // The practice is closed, so nobody can bind it: the export reads through the platform connection.
        if (Isolation::enabled()) {
            Isolation::usePlatform();
        }
        $path = $builder->build($offboarding, $user);
        $service->recordDownload($offboarding);

        return response()->download($path, 'practice-export-'.now()->format('Ymd').'.zip', [
            'Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
