<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_mapping_id',
        'jira_issue_key',
        'linear_issue_id',
        'linear_issue_identifier',
    ];

    public function projectMapping(): BelongsTo
    {
        return $this->belongsTo(ProjectMapping::class);
    }
}
