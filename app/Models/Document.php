<?php

namespace App\Models;

use App\Support\AccessContext;
use App\Support\MatterAccess;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use HasUuids, SoftDeletes;

    /**
     * Scope, tenant, owner and links are set explicitly by the code that creates
     * a document, so a request can never choose them through mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = ['title', 'category', 'confidentiality', 'folder_id'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<DocumentFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    /** @return BelongsTo<VerificationRequest, $this> */
    public function verificationRequest(): BelongsTo
    {
        return $this->belongsTo(VerificationRequest::class);
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    /** @return HasMany<DocumentShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(DocumentShare::class);
    }

    public function latestVersion(): ?DocumentVersion
    {
        return $this->versions()->orderByDesc('version_number')->first();
    }

    /** Neither a matter nor a client. */
    public function isGeneralTenantDocument(): bool
    {
        return $this->scope === 'tenant' && $this->matter_id === null && $this->client_id === null;
    }

    /**
     * Documents this user may list. Mirrors DocumentPolicy::view.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->status !== 'active') {
            return $query->whereRaw('1 = 0');
        }

        $tenant = AccessContext::currentTenantId();
        $canVault = AccessContext::citizenCan($user, 'vault.documents.manage_own');
        $canTenantView = AccessContext::can($user, 'tenant_documents.view', $tenant);
        $canPrivileged = AccessContext::can($user, 'tenant_documents.view_privileged', $tenant)
            && AccessContext::can($user, 'matters.view_all', $tenant);
        $canReceived = AccessContext::can($user, 'shared_documents.view', $tenant);
        $isStaff = AccessContext::staffCan($user, 'verification.documents.view');

        return $query->where(function (Builder $visible) use ($user, $tenant, $canVault, $canTenantView, $canPrivileged, $canReceived, $isStaff) {
            $visible->whereRaw('1 = 0');

            if ($canVault) {
                $visible->orWhere(fn (Builder $q) => $q->where('scope', 'personal')->where('owner_user_id', $user->id));
            }

            if ($canTenantView) {
                $visible->orWhere(function (Builder $q) use ($user, $tenant, $canPrivileged) {
                    $q->where('scope', 'tenant')->where('tenant_id', $tenant)->where(function (Builder $class) use ($user) {
                        $class->where(fn (Builder $general) => $general->whereNull('matter_id')->whereNull('client_id'))
                            ->orWhereIn('matter_id', MatterAccess::visibleMatters(Matter::query(), $user)->select('matters.id'))
                            ->orWhereIn('client_id', MatterAccess::visibleClients(Client::query(), $user)->select('clients.id'));
                    });

                    if (! $canPrivileged) {
                        // RC-PRIV: without matters.view_all, only a matter's lead or associate.
                        $canViewPrivileged = AccessContext::can($user, 'tenant_documents.view_privileged', $tenant);
                        $q->where(function (Builder $privilege) use ($user, $canViewPrivileged) {
                            $privilege->where('confidentiality', '<>', 'privileged');
                            if ($canViewPrivileged) {
                                $privilege->orWhereIn('matter_id', DB::table('matter_assignments')
                                    ->where('membership_id', (string) MatterAccess::me($user))->whereNull('unassigned_at')
                                    ->whereIn('role_on_matter', ['lead', 'associate'])->select('matter_id'));
                            }
                        });
                    }
                });
            }

            $visible->orWhereHas('shares', function (Builder $share) use ($user, $tenant, $canReceived) {
                $share->active()->where(function (Builder $grantee) use ($user, $tenant, $canReceived) {
                    $grantee->where('grantee_user_id', $user->id);
                    if ($canReceived) {
                        $grantee->orWhere('grantee_tenant_id', $tenant);
                    }
                });
            });

            $visible->orWhereHas('verificationRequest.advocateProfile', fn (Builder $profile) => $profile->where('user_id', $user->id));
            $visible->orWhereHas('verificationRequest.mediatorProfile', fn (Builder $profile) => $profile->where('user_id', $user->id));

            if ($isStaff) {
                $visible->orWhereHas('verificationRequest', fn (Builder $request) => $request->whereIn('status', VerificationRequest::STAFF_VISIBLE_STATUSES));
            }
        });
    }
}
