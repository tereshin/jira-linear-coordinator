<?php

namespace App\Console\Commands;

use App\Models\ProjectMapping;
use Illuminate\Console\Command;

class AddMappingCommand extends Command
{
    protected $signature = 'sync:add-mapping {jiraProjectKey} {linearTeamId} {linearTeamName}';
    protected $description = 'Add a new Jira ↔ Linear project mapping';

    public function handle(): int
    {
        $jiraProjectKey = strtoupper($this->argument('jiraProjectKey'));
        $linearTeamId   = $this->argument('linearTeamId');
        $linearTeamName = $this->argument('linearTeamName');

        ProjectMapping::updateOrCreate(
            ['jira_project_key' => $jiraProjectKey],
            [
                'linear_team_id'   => $linearTeamId,
                'linear_team_name' => $linearTeamName,
                'is_active'        => true,
            ]
        );

        $this->info("Mapping added: {$jiraProjectKey} ↔ {$linearTeamName} ({$linearTeamId})");

        return 0;
    }
}
