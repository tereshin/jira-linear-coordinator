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

        $teamLabelMap = $this->linearService->getTeamLabelNameToIdMap($mapping->linear_team_id);

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

                $linearStateId = null;
                $jiraStatusName = $jiraIssue['fields']['status']['name'] ?? null;
                if ($jiraStatusName) {
                    $mappedLinearStatus = config('sync.status_map.jira_to_linear.' . $jiraStatusName, $jiraStatusName);
                    $linearStateId       = $this->linearService->getStateIdByName($mapping->linear_team_id, $mappedLinearStatus);
                }

                $jiraLabelStrings = $jiraIssue['fields']['labels'] ?? [];
                if (!is_array($jiraLabelStrings)) {
                    $jiraLabelStrings = [];
                }
                $jiraLabelStrings = array_values(array_filter($jiraLabelStrings, fn ($v) => is_string($v) && $v !== ''));
                $linearLabelIds   = $this->linearService->jiraLabelNamesToExistingLinearLabelIds($jiraLabelStrings, $teamLabelMap);

                $this->linearService->updateIssue(
                    $linearIssue['id'],
                    $linearIssue['title'] ?? '',
                    $linearIssue['description'] ?? '',
                    $linearStateId,
                    $linearLabelIds
                );
            } else {
                $stateId = $this->linearService->getStateIdForIssueCreatedFromJira($mapping->linear_team_id);

                $jiraLabelStrings = $jiraIssue['fields']['labels'] ?? [];
                if (!is_array($jiraLabelStrings)) {
                    $jiraLabelStrings = [];
                }
                $jiraLabelStrings = array_values(array_filter($jiraLabelStrings, fn ($v) => is_string($v) && $v !== ''));
                $linearLabelIds   = $this->linearService->jiraLabelNamesToExistingLinearLabelIds($jiraLabelStrings, $teamLabelMap);

                $created = $this->linearService->createIssue(
                    $mapping->linear_team_id,
                    $title,
                    $description,
                    $stateId,
                    $mapping->linear_project_id,
                    $linearLabelIds
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

            $linearLabelNamesForJira = $this->linearIssueToJiraLabelNames($linearIssue);

            $jiraIssue = $this->jiraService->createIssue(
                $mapping->jira_project_key,
                $linearIssue['title'] ?? '',
                $linearIssue['description'] ?? '',
                $linearLabelNamesForJira
            );

            $createdJiraKey = $jiraIssue['key'];

            IssueMapping::create([
                'project_mapping_id'      => $mapping->id,
                'jira_issue_key'          => $createdJiraKey,
                'linear_issue_id'         => $linearIssue['id'],
                'linear_issue_identifier' => $linearIssue['identifier'],
            ]);

            $linearStateName = $linearIssue['state']['name'] ?? null;
            if ($linearStateName) {
                $mappedJiraStatus = config('sync.status_map.linear_to_jira.' . $linearStateName, $linearStateName);
                $this->jiraService->transitionIssue($createdJiraKey, $mappedJiraStatus);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function linearIssueToJiraLabelNames(array $linearIssue): array
    {
        $labels = $linearIssue['labels'] ?? null;
        $nodes  = null;
        if (is_array($labels) && isset($labels['nodes']) && is_array($labels['nodes'])) {
            $nodes = $labels['nodes'];
        } elseif (is_array($labels)) {
            $nodes = $labels;
        }

        if ($nodes === null) {
            return [];
        }

        $names = [];
        foreach ($nodes as $label) {
            if (is_string($label)) {
                $names[] = $label;
                continue;
            }
            if (is_array($label) && isset($label['name']) && is_string($label['name']) && $label['name'] !== '') {
                $names[] = $label['name'];
            }
        }

        return array_values(array_unique($names));
    }
}
