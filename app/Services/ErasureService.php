<?php

namespace App\Services;

use App\Models\DataPrincipalRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\LegalHold;
use App\Models\User;
use App\Services\Documents\DocumentVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Carries out an approved erasure request. Personal details are removed, personal
 * files are crypto-shredded, and everything kept is listed with a reason. Which
 * data must be kept, and for how long, is for counsel to confirm (retention rules).
 */
class ErasureService
{
    public function __construct(private DocumentVault $vault, private AuditLogWriter $audit) {}

    public function execute(DataPrincipalRequest $request): void
    {
        /** @var User $user */
        $user = $request->user;
        if (LegalHold::isActive('user', $user->id)) {
            throw ValidationException::withMessages(['status' => 'A legal hold covers this person, so the account cannot be erased yet.']);
        }

        DB::transaction(function () use ($request, $user) {
            $retained = [];

            foreach (Document::query()->where(['scope' => 'personal', 'owner_user_id' => $user->id])->get() as $document) {
                if (LegalHold::isActive('document', $document->id)) {
                    $this->action($request, 'documents', $document->id, 'retained', ['reason' => 'legal_hold']);
                    $retained[] = ['type' => 'document', 'reason' => 'legal_hold'];

                    continue;
                }
                DocumentVersion::query()->where('document_id', $document->id)->get()
                    ->each(fn (DocumentVersion $version) => $this->vault->shred($version, 'erasure'));
                $document->delete();
                $this->action($request, 'documents', $document->id, 'shredded');
            }

            DB::table('customer_profiles')->where('user_id', $user->id)->update(['display_name' => null, 'business_name' => null, 'gstin' => null, 'address' => null, 'updated_at' => now(), 'deleted_at' => now()]);
            DB::table('advocate_profiles')->where('user_id', $user->id)->update(['bio' => null, 'office_address' => null, 'enrollment_number' => null, 'is_directory_listed' => false, 'erased_at' => now(), 'deleted_at' => now(), 'updated_at' => now()]);
            DB::table('mediator_profiles')->where('user_id', $user->id)->update(['accreditation_number' => null, 'is_available' => false, 'erased_at' => now(), 'deleted_at' => now(), 'updated_at' => now()]);
            DB::table('personal_access_tokens')->where(['tokenable_type' => 'user', 'tokenable_id' => $user->id])->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('user_devices')->where('user_id', $user->id)->delete();
            DB::table('notification_preferences')->where('user_id', $user->id)->delete();

            $user->forceFill([
                'name' => 'Erased user',
                'email' => null, 'phone' => null, 'password' => null, 'remember_token' => null, 'two_factor_secret' => null,
                'email_verified_at' => null, 'phone_verified_at' => null, 'status' => 'erased', 'erased_at' => now(),
                // C44: destroying the key makes the consent evidence unreadable. The chain itself stays valid.
                'consent_key_wrapped' => null, 'consent_key_destroyed_at' => now(),
            ])->save();
            $this->action($request, 'users', $user->id, 'anonymized');

            foreach (['consultation_requests' => 'customer_user_id', 'emergency_requests' => 'customer_user_id', 'odr_participants' => 'user_id'] as $table => $column) {
                if (DB::table($table)->where($column, $user->id)->exists()) {
                    $retained[] = ['type' => $table, 'reason' => 'other_parties_rely_on_this_record'];
                    $this->action($request, $table, $user->id, 'retained', ['reason' => 'other_parties_rely_on_this_record']);
                }
            }
            $retained[] = ['type' => 'consent_records', 'reason' => 'tamper_evident_ledger_evidence_key_destroyed'];
            $retained[] = ['type' => 'audit_logs', 'reason' => 'security_and_legal_accountability'];

            $request->forceFill([
                'status' => array_filter($retained, fn ($r) => $r['reason'] === 'legal_hold') === [] ? 'completed' : 'partially_fulfilled',
                'retained_items' => $retained,
                'completed_at' => now(),
            ])->save();
            $this->audit->record('privacy.erasure_executed', 'data_principal_request', $request->id, null, null, ['retained_count' => count($retained)]);
        });
    }

    /** @param array<string, mixed>|null $details */
    private function action(DataPrincipalRequest $request, string $table, string $id, string $action, ?array $details = null): void
    {
        DB::table('erasure_actions')->insert([
            'id' => (string) Str::uuid7(), 'request_id' => $request->id, 'target_table' => $table, 'target_id' => $id,
            'action' => $action, 'details' => $details === null ? null : json_encode($details), 'executed_at' => now(),
        ]);
    }
}
