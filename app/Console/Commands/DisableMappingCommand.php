<?php

namespace App\Console\Commands;

use App\Models\ProjectMapping;
use Illuminate\Console\Command;

class DisableMappingCommand extends Command
{
    protected $signature = 'sync:disable-mapping {jiraProjectKey}';
    protected $description = 'Disable sync for a Jira ↔ Linear project mapping';

    public function handle(): int
    {
        $key     = strtoupper($this->argument('jiraProjectKey'));
        $mapping = ProjectMapping::where('jira_project_key', $key)->first();

        if (!$mapping) {
            $this->error("No mapping found for project key: {$key}");
            return 1;
        }

        $mapping->update(['is_active' => false]);
        $this->info("Sync disabled for: {$key}");

        return 0;
    }
}
