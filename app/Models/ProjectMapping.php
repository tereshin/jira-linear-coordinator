<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'jira_project_key',
        'linear_team_id',
        'linear_team_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function issueMappings(): HasMany
    {
        return $this->hasMany(IssueMapping::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
