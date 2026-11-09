<?php

namespace App\Http\Controllers\Web\Practice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Models\Document;
use App\Models\KnowledgeItem;
use App\Models\Matter;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentVault;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** D8.10. Search looks at titles, summaries and tags only, never at file contents. */
class KnowledgeController extends Controller
{
    public const TYPES = ['pleading', 'template', 'opinion', 'judgment', 'precedent', 'checklist'];

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $search = trim((string) $request->query('q'));
        $canContribute = AccessContext::tenantCan($user, 'knowledge.contribute') || AccessContext::tenantCan($user, 'knowledge.approve');

        $items = KnowledgeItem::query()->where('tenant_id', AccessContext::currentTenantId())
            ->whereIn('status', $canContribute ? ['approved', 'draft'] : ['approved'])
            ->when($request->query('type'), fn (Builder $q, $type) => $q->where('item_type', $type))
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $s) => $s->where('title', 'like', $like)->orWhere('summary', 'like', $like)->orWhere('tags', 'like', $like));
            })->latest()->paginate(25)->withQueryString();

        return view('practice.knowledge', [
            'items' => $items,
            'types' => self::TYPES,
            'search' => $search,
            'type' => (string) $request->query('type'),
            'canContribute' => AccessContext::tenantCan($user, 'knowledge.contribute'),
            'canApprove' => AccessContext::tenantCan($user, 'knowledge.approve'),
            'canArchive' => AccessContext::tenantCan($user, 'knowledge.archive'),
            'matters' => MatterAccess::visibleMatters(Matter::query(), $user)->orderByDesc('opened_at')->limit(200)->get(['id', 'matter_number', 'title']),
            'areas' => DB::table('practice_areas')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, DocumentService $documents): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::tenantCan($user, 'knowledge.contribute'), 403);
        $data = $this->validated($request);
        $this->assertMatterVisible($user, $data['source_matter_id'] ?? null);
        $tenant = (string) AccessContext::currentTenantId();

        DB::transaction(function () use ($request, $user, $data, $tenant, $documents) {
            $item = new KnowledgeItem($data);
            $file = $request->file('file');
            $documentId = null;
            if ($file !== null) {
                // The attachment is a purpose-bound general document (D8.10).
                $documentId = $documents->create($user, $file, ['title' => $data['title'], 'category' => 'other', 'confidentiality' => 'normal'], ['scope' => 'tenant', 'tenant_id' => $tenant])->id;
            }
            $item->forceFill(['tenant_id' => $tenant, 'document_id' => $documentId, 'status' => 'draft', 'created_by' => $user->id])->save();
        });

        return back()->with('status', 'Saved as a draft. A partner must approve it before others rely on it.');
    }

    public function approve(Request $request, KnowledgeItem $item): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($item->tenant_id === AccessContext::currentTenantId(), 404);
        abort_unless(AccessContext::tenantCan($user, 'knowledge.approve'), 403);
        abort_unless($item->status === 'draft', 422);
        $item->forceFill(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()])->save();

        return back()->with('status', 'Approved.');
    }

    public function archive(Request $request, KnowledgeItem $item): RedirectResponse
    {
        abort_unless($item->tenant_id === AccessContext::currentTenantId(), 404);
        abort_unless(AccessContext::tenantCan($this->user($request), 'knowledge.archive'), 403);
        abort_unless($item->status === 'approved', 422);
        $item->forceFill(['status' => 'archived'])->save();

        return back()->with('status', 'Archived.');
    }

    public function download(Request $request, KnowledgeItem $item, DocumentVault $vault): StreamedResponse
    {
        $user = $this->user($request);
        abort_unless($item->tenant_id === AccessContext::currentTenantId() && $item->document_id !== null, 404);
        $allowed = $item->status === 'approved'
            ? AccessContext::tenantCan($user, 'knowledge.view')
            : $item->status === 'draft' && (AccessContext::tenantCan($user, 'knowledge.contribute') || AccessContext::tenantCan($user, 'knowledge.approve'));
        abort_unless($allowed, 404);

        /** @var Document $document */
        $document = Document::query()->findOrFail($item->document_id);
        $version = $document->latestVersion();
        abort_if($version === null, 404);
        abort_if($version->isShredded(), 410);

        return $vault->download($document, $version, $user);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'item_type' => ['required', Rule::in(self::TYPES)],
            'title' => ['required', 'string', 'max:200'],
            'practice_area_id' => ['nullable', 'integer', 'exists:practice_areas,id'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'string', 'max:500'],
            'source_matter_id' => ['nullable', 'uuid'],
            'file' => ['nullable', ...array_filter(UploadDocumentRequest::fileRules(), fn ($rule) => $rule !== 'required')],
        ]);
        unset($data['file']);
        $data['tags'] = array_values(array_filter(array_map('trim', explode(',', (string) ($data['tags'] ?? '')))));

        return $data;
    }

    private function assertMatterVisible(User $user, ?string $matterId): void
    {
        if ($matterId === null) {
            return;
        }
        $matter = Matter::query()->find($matterId);
        abort_unless($matter !== null && MatterAccess::canView($user, $matter), 404);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
