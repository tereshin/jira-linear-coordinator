<?php

namespace Tests\Feature;

use App\Jobs\ProcessJiraEventJob;
use App\Models\IssueMapping;
use App\Models\ProjectMapping;
use App\Services\JiraService;
use App\Services\LinearService;
use App\Services\SyncLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessJiraEventJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_skips_draft_status_without_syncing(): void
    {
        $linearService = Mockery::mock(LinearService::class);
        $lockService = Mockery::mock(SyncLockService::class);
        $jiraService = Mockery::mock(JiraService::class);

        $jiraService->shouldReceive('jiraDescriptionToLinearText')
            ->once()
            ->andReturn('');
        $lockService->shouldNotReceive('isLocked');
        $linearService->shouldNotReceive('updateIssue');
        $linearService->shouldNotReceive('createIssue');

        $job = new ProcessJiraEventJob('jira:issue_updated', [
            'key' => 'ABC-1',
            'fields' => [
                'summary' => 'Draft issue',
                'status' => ['name' => 'Draft'],
            ],
        ]);

        $job->handle($linearService, $lockService, $jiraService);
    }

    public function test_it_merges_existing_linear_labels_on_jira_update(): void
    {
        $projectMapping = ProjectMapping::create([
            'jira_project_key' => 'ABC',
            'linear_team_id' => 'team-1',
            'linear_team_name' => 'Team One',
            'linear_project_id' => null,
            'linear_project_name' => null,
            'is_active' => true,
        ]);

        IssueMapping::create([
            'project_mapping_id' => $projectMapping->id,
            'jira_issue_key' => 'ABC-1',
            'linear_issue_id' => 'linear-1',
            'linear_issue_identifier' => 'LIN-1',
        ]);

        $linearService = Mockery::mock(LinearService::class);
        $lockService = Mockery::mock(SyncLockService::class);
        $jiraService = Mockery::mock(JiraService::class);

        $jiraService->shouldReceive('jiraDescriptionToLinearText')
            ->once()
            ->andReturn('Converted body');
        $lockService->shouldReceive('isLocked')
            ->once()
            ->with('linear', 'ABC-1')
            ->andReturn(false);
        $linearService->shouldReceive('getTeamLabelNameToIdMap')
            ->once()
            ->with('team-1')
            ->andReturn(['incoming-label' => 'label-incoming']);
        $linearService->shouldReceive('jiraLabelNamesToExistingLinearLabelIds')
            ->once()
            ->with(['incoming-label'], ['incoming-label' => 'label-incoming'])
            ->andReturn(['label-incoming']);
        $linearService->shouldReceive('getStateIdByName')
            ->once()
            ->with('team-1', 'Todo')
            ->andReturn('state-todo');
        $linearService->shouldReceive('getIssueLabelIds')
            ->once()
            ->with('linear-1')
            ->andReturn(['label-existing']);
        $lockService->shouldReceive('lock')
            ->once()
            ->with('jira', 'linear-1');
        $linearService->shouldReceive('updateIssue')
            ->once()
            ->withArgs(function (string $linearId, string $title, string $description, ?string $stateId, ?array $labelIds): bool {
                sort($labelIds);

                return $linearId === 'linear-1'
                    && $title === 'Issue title'
                    && $description === 'Converted body'
                    && $stateId === 'state-todo'
                    && $labelIds === ['label-existing', 'label-incoming'];
            });
        $jiraService->shouldReceive('getIssueAttachments')
            ->once()
            ->with('ABC-1')
            ->andReturn([]);
        $lockService->shouldReceive('clearExpired')
            ->once();

        $job = new ProcessJiraEventJob('jira:issue_updated', [
            'key' => 'ABC-1',
            'fields' => [
                'summary' => 'Issue title',
                'description' => ['type' => 'doc', 'content' => []],
                'status' => ['name' => 'Todo'],
                'labels' => ['incoming-label'],
            ],
        ]);

        $job->handle($linearService, $lockService, $jiraService);
    }
}
