<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ContentReport extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['reason', 'details'];

    protected $hidden = ['reporter_user_id'];
}
