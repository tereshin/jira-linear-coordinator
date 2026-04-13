<?php

namespace App\Console\Commands;

use App\Models\IssueMapping;
use App\Models\ProjectMapping;
use App\Services\JiraService;
use App\Services\LinearService;
use Illuminate\Console\Command;

class InitialSyncCommand extends Command
{
    protected $signature = 'sync:initial {--project= : Jira project key to sync (all active if omitted)}';
    protected $description = 'Perform initial sync of pre-existing issues between Jira and Linear';

    public function __construct(
        private readonly JiraService   $jiraService,
        private readonly LinearService $linearService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = ProjectMapping::active();

        if ($project = $this->option('project')) {
            $query->where('jira_project_key', $project);
        }

        $mappings = $query->get();

        if ($mappings->isEmpty()) {
            $this->warn('[InitialSync] No active project mappings found.');
            return 0;
        }

        foreach ($mappings as $mapping) {
            $this->processPair($mapping);
        }

        $this->info('[InitialSync] Done.');
        return 0;
    }

    private function processPair(ProjectMapping $mapping): void
    {
        $this->info("[InitialSync] Processing pair: {$mapping->jira_project_key} ↔ {$mapping->linear_team_name}");

        $jiraIssues   = $this->jiraService->getAllIssues($mapping->jira_project_key);
        $linearIssues = $this->linearService->getAllIssues($mapping->linear_team_id);

        $this->info("[InitialSync]   Fetched " . count($jiraIssues) . " Jira issues, " . count($linearIssues) . " Linear issues");

        // Index Linear issues by title (lowercase) for matching
        $linearByTitle = [];
        foreach ($linearIssues as $li) {
            $linearByTitle[strtolower($li['title'] ?? '')] = $li;
        }

        $mappedLinearIds = IssueMapping::whereIn(
            'linear_issue_id',
            array_column($linearIssues, 'id')
        )->pluck('linear_issue_id')->toArray();

        $matched         = 0;
        $createdInLinear = 0;
        $skipped         = 0;

        foreach ($jiraIssues as $jiraIssue) {
            $jiraKey     = $jiraIssue['key'];
            $title       = $jiraIssue['fields']['summary'] ?? '';
            $description = $jiraIssue['fields']['description'] ?? '';

            // Skip already mapped
            if (IssueMapping::where('jira_issue_key', $jiraKey)->exists()) {
                $skipped++;
                continue;
            }

            $titleKey     = strtolower($title);
            $linearIssue  = $linearByTitle[$titleKey] ?? null;

            if ($linearIssue) {
                IssueMapping::create([
                    'project_mapping_id'      => $mapping->id,
                    'jira_issue_key'          => $jiraKey,
                    'linear_issue_id'         => $linearIssue['id'],
                    'linear_issue_identifier' => $linearIssue['identifier'],
                ]);
                $matched++;
                $mappedLinearIds[] = $linearIssue['id'];
            } else {
                $stateId = null;
                $statusName = $jiraIssue['fields']['status']['name'] ?? null;
                if ($statusName) {
                    $mapped  = config('sync.status_map.jira_to_linear.' . $statusName, $statusName);
                    $stateId = $this->linearService->getStateIdByName($mapping->linear_team_id, $mapped);
                }

                $created = $this->linearService->createIssue(
                    $mapping->linear_team_id,
                    $title,
                    $description,
                    $stateId
                );

                IssueMapping::create([
                    'project_mapping_id'      => $mapping->id,
                    'jira_issue_key'          => $jiraKey,
                    'linear_issue_id'         => $created['id'],
                    'linear_issue_identifier' => $created['identifier'],
                ]);
                $createdInLinear++;
            }
        }

        $this->info("[InitialSync]   Matched by title: {$matched}");
        $this->info("[InitialSync]   Created in Linear: {$createdInLinear}");
        $this->info("[InitialSync]   Already mapped (skipped): {$skipped}");

        // Create unmapped Linear issues in Jira
        foreach ($linearIssues as $linearIssue) {
            if (in_array($linearIssue['id'], $mappedLinearIds, true)) {
                continue;
            }

            $jiraIssue = $this->jiraService->createIssue(
                $mapping->jira_project_key,
                $linearIssue['title'] ?? '',
                $linearIssue['description'] ?? ''
            );

            IssueMapping::create([
                'project_mapping_id'      => $mapping->id,
                'jira_issue_key'          => $jiraIssue['key'],
                'linear_issue_id'         => $linearIssue['id'],
                'linear_issue_identifier' => $linearIssue['identifier'],
            ]);
        }
    }
}
