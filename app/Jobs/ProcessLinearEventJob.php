<?php

namespace App\Jobs;

use App\Models\IssueMapping;
use App\Models\ProjectMapping;
use App\Services\JiraService;
use App\Services\SyncLockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessLinearEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $action,
        private readonly string $type,
        private readonly array  $data,
        private readonly array  $updatedFrom = []
    ) {}

    public function handle(JiraService $jiraService, SyncLockService $lockService): void
    {
        if ($this->type !== 'Issue') {
            return;
        }

        $linearId   = $this->data['id'] ?? null;
        $title       = $this->data['title'] ?? '';
        $description = $this->data['description'] ?? '';
        $stateName    = $this->data['state']['name'] ?? null;
        $stateId      = $this->data['state']['id'] ?? null;
        $teamId       = $this->data['team']['id'] ?? null;
        $projectId    = $this->data['project']['id'] ?? null;
        $jiraLabelNames = $this->linearIssueDataToJiraLabelNamesForSync($this->data);

        if (!$linearId || !$teamId) {
            Log::warning('ProcessLinearEventJob: missing id or team id');
            return;
        }

        $projectMapping = null;

        if ($projectId) {
            $projectMapping = ProjectMapping::active()
                ->where('linear_project_id', $projectId)
                ->first();
        }

        if (!$projectMapping) {
            $projectMapping = ProjectMapping::active()
                ->where('linear_team_id', $teamId)
                ->whereNull('linear_project_id')
                ->first();
        }

        if (!$projectMapping) {
            Log::warning('ProcessLinearEventJob: no active mapping for team', ['teamId' => $teamId, 'projectId' => $projectId]);
            return;
        }

        // Echo protection: skip if this was triggered by a Jira → Linear sync
        if ($lockService->isLocked('jira', $linearId)) {
            Log::info('ProcessLinearEventJob: echo lock active, skipping', ['linearId' => $linearId]);
            return;
        }

        $issueMapping = IssueMapping::where('linear_issue_id', $linearId)->first();

        if ($issueMapping) {
            $mergedJiraLabelNames = $jiraLabelNames;
            if ($jiraLabelNames !== null) {
                $existingJiraLabels = $jiraService->getIssueLabels($issueMapping->jira_issue_key);
                $mergedJiraLabelNames = $this->mergeUniqueStrings($existingJiraLabels, $jiraLabelNames);
                if ($mergedJiraLabelNames === []) {
                    $mergedJiraLabelNames = null;
                }
            }

            $lockService->lock('linear', $issueMapping->jira_issue_key);
            $jiraService->updateIssue($issueMapping->jira_issue_key, $title, $description, $mergedJiraLabelNames);

            if ($this->shouldSyncStateTransition($stateName, is_string($stateId) ? $stateId : null)) {
                $mappedStatus = config('sync.status_map.linear_to_jira.' . $stateName, $stateName);
                $jiraService->transitionIssue($issueMapping->jira_issue_key, $mappedStatus);
            }
        } else {
            if ($jiraLabelNames === []) {
                $jiraLabelNames = null;
            }

            $jiraKey = DB::transaction(function () use ($projectMapping, $linearId, $title, $description, $jiraLabelNames, $jiraService) {
                $jiraIssue = $jiraService->createIssue(
                    $projectMapping->jira_project_key,
                    $title,
                    $description,
                    $jiraLabelNames
                );

                $createdKey = $jiraIssue['key'];

                IssueMapping::updateOrCreate(
                    ['linear_issue_id' => $linearId],
                    [
                        'project_mapping_id'      => $projectMapping->id,
                        'jira_issue_key'          => $createdKey,
                        'linear_issue_identifier' => $this->data['identifier'] ?? '',
                    ]
                );

                return $createdKey;
            });

            if ($stateName) {
                $lockService->lock('linear', $jiraKey);
                $mappedStatus = config('sync.status_map.linear_to_jira.' . $stateName, $stateName);
                $jiraService->transitionIssue($jiraKey, $mappedStatus);
            }
        }

        $lockService->clearExpired();
    }

    /**
     * @return array<int, string>|null null = webhook did not include labels; do not change Jira labels
     */
    private function linearIssueDataToJiraLabelNamesForSync(array $data): ?array
    {
        if (!array_key_exists('labels', $data)) {
            return null;
        }

        $labels = $data['labels'];
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

    /**
     * @param array<int, string> $existing
     * @param array<int, string> $incoming
     * @return array<int, string>
     */
    private function mergeUniqueStrings(array $existing, array $incoming): array
    {
        $unique = [];

        foreach (array_merge($existing, $incoming) as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $unique[$value] = true;
        }

        return array_keys($unique);
    }

    private function shouldSyncStateTransition(?string $stateName, ?string $stateId): bool
    {
        if ($stateName === null || $stateName === '') {
            return false;
        }

        if ($this->action === 'create') {
            return true;
        }

        if (array_key_exists('stateId', $this->updatedFrom)) {
            $previousStateId = $this->updatedFrom['stateId'];
            if (is_string($previousStateId) && $previousStateId !== '' && $stateId !== null && $stateId !== '') {
                return $previousStateId !== $stateId;
            }

            return true;
        }

        if (array_key_exists('state', $this->updatedFrom)) {
            $previousState = $this->updatedFrom['state'];

            if (is_array($previousState)) {
                $previousStateId = $previousState['id'] ?? null;
                if (is_string($previousStateId) && $previousStateId !== '' && $stateId !== null && $stateId !== '') {
                    return $previousStateId !== $stateId;
                }

                $previousStateName = $previousState['name'] ?? null;

                return !is_string($previousStateName) || $previousStateName !== $stateName;
            }

            if (is_string($previousState) && $previousState !== '') {
                if ($stateId !== null && $previousState === $stateId) {
                    return false;
                }

                return $previousState !== $stateName;
            }

            return true;
        }

        return false;
    }
}
