<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. The database rejects every update and delete.
 */
class DocumentAccessLog extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['document_id', 'version_id', 'user_id', 'tenant_id', 'action', 'ip', 'user_agent', 'request_id'];
}
