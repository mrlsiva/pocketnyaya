<?php

namespace App\Http\Controllers\Web\Practice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Practice\ClientRequest;
use App\Models\Client;
use App\Models\LegalHold;
use App\Models\Matter;
use App\Models\User;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $search = trim((string) $request->query('q'));

        $clients = MatterAccess::visibleClients(Client::query(), $user)
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('phone_bidx', Client::phoneIndex($search))))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('practice.clients.index', ['clients' => $clients, 'search' => $search]);
    }

    public function create(): View
    {
        Gate::authorize('create', Client::class);

        return view('practice.clients.form', ['client' => new Client, 'states' => $this->states()]);
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        Gate::authorize('create', Client::class);

        $client = new Client($request->validated());
        $client->forceFill(['tenant_id' => AccessContext::currentTenantId()])->save();

        return redirect()->route('practice.clients.show', $client)->with('status', 'Client added.');
    }

    public function show(Request $request, Client $client): View
    {
        Gate::authorize('view', $client);
        /** @var User $user */
        $user = $request->user();

        return view('practice.clients.show', [
            'client' => $client,
            'matters' => MatterAccess::visibleMatters(Matter::query(), $user)->where('client_id', $client->id)->orderByDesc('opened_at')->get(),
        ]);
    }

    public function edit(Client $client): View
    {
        Gate::authorize('update', $client);

        return view('practice.clients.form', ['client' => $client, 'states' => $this->states()]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('update', $client);
        $client->update($request->validated());

        return redirect()->route('practice.clients.show', $client)->with('status', 'Client updated.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        Gate::authorize('delete', $client);
        abort_if(LegalHold::coversClient($client), 422, 'A legal hold covers this client, so it cannot be removed.');
        $client->delete();

        return redirect()->route('practice.clients.index')->with('status', 'Client removed.');
    }

    /** @return Collection<int, \stdClass> */
    private function states()
    {
        return DB::table('states')->orderBy('name')->get(['code', 'name']);
    }
}
