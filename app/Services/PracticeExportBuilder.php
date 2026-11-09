<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ConsultationMessage;
use App\Models\ConsultationRequest;
use App\Models\DiaryEntry;
use App\Models\Document;
use App\Models\FeeArrangement;
use App\Models\FeeScheduleItem;
use App\Models\Hearing;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\KnowledgeItem;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\MatterExpense;
use App\Models\MatterNote;
use App\Models\MatterParty;
use App\Models\MatterTask;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\RetainerPlan;
use App\Models\RetainerWorkItem;
use App\Models\TenantOffboarding;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Documents\DocumentVault;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Builds a closing practice's export as a ZIP: its records as JSON (encrypted fields decrypted
 * through the models), plus the latest version of each of its documents. The file is built
 * in the system temp folder when downloaded, sent once and deleted, and never stored.
 */
class PracticeExportBuilder
{
    /** @var array<string, class-string<Model>> Practice records, all keyed by tenant_id. */
    private const TENANT_TABLES = [
        'clients' => Client::class, 'matters' => Matter::class, 'matter_parties' => MatterParty::class, 'matter_notes' => MatterNote::class,
        'matter_tasks' => MatterTask::class, 'matter_assignments' => MatterAssignment::class, 'hearings' => Hearing::class, 'diary_entries' => DiaryEntry::class,
        'consultation_requests' => ConsultationRequest::class, 'knowledge_items' => KnowledgeItem::class, 'documents' => Document::class,
        'fee_arrangements' => FeeArrangement::class, 'fee_schedule_items' => FeeScheduleItem::class, 'retainer_plans' => RetainerPlan::class,
        'retainer_work_items' => RetainerWorkItem::class, 'time_entries' => TimeEntry::class, 'matter_expenses' => MatterExpense::class,
        'invoices' => Invoice::class, 'invoice_lines' => InvoiceLine::class, 'payments' => Payment::class, 'payment_allocations' => PaymentAllocation::class,
    ];

    public function __construct(private DocumentVault $vault) {}

    /** @return string the path of a temporary ZIP; the caller deletes it after sending */
    public function build(TenantOffboarding $offboarding, User $recipient): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pn-export-');
        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export file.');
        }

        $tenantId = $offboarding->tenant_id;
        $manifest = ['practice_id' => $tenantId, 'generated_at' => now()->toIso8601String(), 'records' => [], 'documents' => []];

        foreach (self::TENANT_TABLES as $table => $class) {
            $rows = $this->query($class)->where('tenant_id', $tenantId)->get()->map(fn (Model $m) => $m->attributesToArray())->all();
            $zip->addFromString("records/{$table}.json", (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $manifest['records'][$table] = count($rows);
        }

        $consultations = ConsultationRequest::query()->where('tenant_id', $tenantId)->pluck('id');
        $messages = ConsultationMessage::query()->whereIn('consultation_request_id', $consultations)->get()->map(fn (Model $m) => $m->attributesToArray())->all();
        $zip->addFromString('records/consultation_messages.json', (string) json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $manifest['records']['consultation_messages'] = count($messages);

        foreach (Document::query()->where(['tenant_id' => $tenantId, 'scope' => 'tenant'])->get() as $document) {
            $version = $document->latestVersion();
            if ($version === null || $version->isShredded()) {
                $manifest['documents'][] = ['id' => $document->id, 'title' => $document->title, 'included' => false];

                continue;
            }
            $contents = $this->vault->readContents($version);
            $name = 'documents/'.$document->id.'-'.Str::limit(preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($this->vault->originalFilename($version) ?? 'file')), 120, '');
            $zip->addFromString($name, $contents);
            $this->vault->log($document, 'download', $recipient, $version->id);
            $manifest['documents'][] = ['id' => $document->id, 'title' => $document->title, 'file' => $name, 'sha256' => hash('sha256', $contents), 'included' => true];
        }

        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('README.txt', "Export of a closing practice from PocketNyaya.\nrecords/ holds the practice's records as JSON. documents/ holds the latest version of each document.\nmanifest.json lists counts and a SHA-256 checksum for every file.\nThis export contains confidential client information. Store it securely.\n");
        $zip->close();

        return $path;
    }

    /**
     * @param  class-string<Model>  $class
     * @return Builder<Model>
     */
    private function query(string $class)
    {
        $query = $class::query();

        // Deleted records are part of the firm's file too, so the soft-delete filter is lifted.
        return in_array(SoftDeletes::class, class_uses_recursive($class), true) ? $query->withoutGlobalScope(SoftDeletingScope::class) : $query;
    }
}
