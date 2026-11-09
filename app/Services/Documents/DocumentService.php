<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DocumentService
{
    public function __construct(private DocumentVault $vault) {}

    /**
     * @param  array<string, mixed>  $details  title, category, confidentiality, folder_id
     * @param  array<string, string|null>  $placement  scope, tenant_id, matter_id, client_id, verification_request_id
     */
    public function create(User $user, UploadedFile $file, array $details, array $placement): Document
    {
        return DB::transaction(function () use ($user, $file, $details, $placement) {
            $document = new Document($details);
            $document->forceFill($placement + ['owner_user_id' => $user->id, 'source' => 'upload'])->save();
            $this->vault->addVersion($document, $file, $user);

            return $document->fresh() ?? $document;
        });
    }
}
