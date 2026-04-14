<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncedAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'jira_attachment_id',
        'jira_issue_key',
        'linear_issue_id',
        'linear_attachment_id',
        'linear_asset_url',
        'filename',
        'mime_type',
    ];
}
