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
        $stateName   = $this->data['state']['name'] ?? null;
        $teamId      = $this->data['team']['id'] ?? null;
        $projectId   = $this->data['project']['id'] ?? null;

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
            $lockService->lock('linear', $issueMapping->jira_issue_key);
            $jiraService->updateIssue($issueMapping->jira_issue_key, $title, $description);

            if ($stateName) {
                $previousStateName = $this->updatedFrom['state']['name'] ?? null;
                if (!$previousStateName || $previousStateName !== $stateName) {
                    $mappedStatus = config('sync.status_map.linear_to_jira.' . $stateName, $stateName);
                    $jiraService->transitionIssue($issueMapping->jira_issue_key, $mappedStatus);
                }
            }
        } else {
            DB::transaction(function () use ($projectMapping, $linearId, $title, $description, $jiraService) {
                $jiraIssue = $jiraService->createIssue(
                    $projectMapping->jira_project_key,
                    $title,
                    $description
                );

                $jiraKey = $jiraIssue['key'];

                IssueMapping::updateOrCreate(
                    ['linear_issue_id' => $linearId],
                    [
                        'project_mapping_id'      => $projectMapping->id,
                        'jira_issue_key'          => $jiraKey,
                        'linear_issue_identifier' => $this->data['identifier'] ?? '',
                    ]
                );
            });
        }

        $lockService->clearExpired();
    }
}
