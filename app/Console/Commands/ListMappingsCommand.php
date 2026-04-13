<?php

namespace App\Console\Commands;

use App\Models\ProjectMapping;
use Illuminate\Console\Command;

class ListMappingsCommand extends Command
{
    protected $signature = 'sync:list-mappings';
    protected $description = 'List all Jira ↔ Linear project mappings';

    public function handle(): int
    {
        $mappings = ProjectMapping::all(['jira_project_key', 'linear_team_id', 'linear_team_name', 'is_active']);

        if ($mappings->isEmpty()) {
            $this->info('No project mappings configured.');
            return 0;
        }

        $rows = $mappings->map(fn($m) => [
            $m->jira_project_key,
            $m->linear_team_id,
            $m->linear_team_name,
            $m->is_active ? '✅ yes' : '❌ no',
        ])->toArray();

        $this->table(
            ['Jira Project', 'Linear Team ID', 'Linear Team', 'Active'],
            $rows
        );

        return 0;
    }
}
