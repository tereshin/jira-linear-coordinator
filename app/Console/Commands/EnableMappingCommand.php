<?php

namespace App\Console\Commands;

use App\Models\ProjectMapping;
use Illuminate\Console\Command;

class EnableMappingCommand extends Command
{
    protected $signature = 'sync:enable-mapping {jiraProjectKey}';
    protected $description = 'Enable sync for a Jira ↔ Linear project mapping';

    public function handle(): int
    {
        $key     = strtoupper($this->argument('jiraProjectKey'));
        $mapping = ProjectMapping::where('jira_project_key', $key)->first();

        if (!$mapping) {
            $this->error("No mapping found for project key: {$key}");
            return 1;
        }

        $mapping->update(['is_active' => true]);
        $this->info("Sync enabled for: {$key}");

        return 0;
    }
}
