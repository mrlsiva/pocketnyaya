<?php

namespace App\Http\Controllers\Web\Staff;

use App\Http\Controllers\Controller;
use App\Models\MediatorProfile;
use App\Models\OdrDispute;
use App\Models\SettlementTemplate;
use App\Models\User;
use App\Services\AuditLogWriter;
use App\Services\OdrService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * B3-7: staff see intake fields and the screening record only. They never open
 * messages, evidence, offers or settlements.
 */
class OdrIntakeController extends Controller
{
    public function index(): View
    {
        return view('staff.odr.index', [
            'screening' => OdrDispute::query()->where('status', 'screening')->oldest()->get(),
            'needingMediator' => OdrDispute::query()->whereIn('status', ['negotiation', 'mediation', 'settlement_drafting'])
                ->whereNotIn('id', DB::table('odr_mediator_assignments')->whereIn('status', ['proposed', 'accepted'])->select('dispute_id'))->oldest()->get(),
            'mediators' => MediatorProfile::query()->with('user:id,name')->where(['verification_status' => 'verified', 'is_available' => true])->get(),
            'answers' => DB::table('odr_screenings')->orderBy('created_at')->get()->groupBy('dispute_id'),
        ]);
    }

    public function review(Request $request, OdrDispute $dispute, OdrService $odr): RedirectResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        $data = $request->validate(['result' => ['required', Rule::in(['suitable', 'unsuitable'])], 'note' => ['required', 'string', 'max:500']]);
        $odr->review($dispute, $staff, $data['result'], $data['note']);

        return back()->with('status', 'Review recorded.');
    }

    public function assign(Request $request, OdrDispute $dispute, OdrService $odr): RedirectResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        $data = $request->validate(['mediator_profile_id' => ['required', 'uuid']]);
        $mediator = MediatorProfile::query()->find($data['mediator_profile_id']);
        abort_if($mediator === null, 422);
        $odr->proposeMediator($dispute, $mediator, $staff);

        return back()->with('status', 'Mediator proposed. They must disclose any conflict and accept.');
    }

    public function templates(): View
    {
        return view('staff.odr.templates', ['templates' => SettlementTemplate::query()->orderBy('category')->orderByDesc('version')->get(), 'categories' => OdrDispute::CATEGORIES]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(OdrDispute::CATEGORIES)],
            'title' => ['required', 'string', 'max:200'],
            'body_template' => ['required', 'string', 'max:20000'],
        ]);
        $data['version'] = (int) SettlementTemplate::query()->where('category', $data['category'])->max('version') + 1;
        SettlementTemplate::query()->create($data);

        return back()->with('status', 'Template saved as a draft. Record legal review before it can be used.');
    }

    public function updateTemplate(Request $request, SettlementTemplate $template, AuditLogWriter $audit): RedirectResponse
    {
        $data = $request->validate(['legal_review_status' => ['required', Rule::in(['reviewed', 'retired'])]]);
        $template->forceFill(['legal_review_status' => $data['legal_review_status'], 'reviewed_at' => $data['legal_review_status'] === 'reviewed' ? now() : $template->reviewed_at])->save();
        $audit->record('odr.template_'.$data['legal_review_status'], 'settlement_template', $template->id);

        return back()->with('status', 'Template updated.');
    }
}
