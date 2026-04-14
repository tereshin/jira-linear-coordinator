<?php

namespace Tests\Feature;

use App\Jobs\ProcessLinearEventJob;
use App\Models\IssueMapping;
use App\Models\ProjectMapping;
use App\Services\JiraService;
use App\Services\SyncLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessLinearEventJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_in_review_status_to_done_and_merges_labels(): void
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
            'linear_issue_id' => 'lin-1',
            'linear_issue_identifier' => 'LIN-1',
        ]);

        $jiraService = Mockery::mock(JiraService::class);
        $lockService = Mockery::mock(SyncLockService::class);

        $lockService->shouldReceive('isLocked')
            ->once()
            ->with('jira', 'lin-1')
            ->andReturn(false);
        $jiraService->shouldReceive('getIssueLabels')
            ->once()
            ->with('ABC-1')
            ->andReturn(['existing-label']);
        $lockService->shouldReceive('lock')
            ->once()
            ->with('linear', 'ABC-1');
        $jiraService->shouldReceive('updateIssue')
            ->once()
            ->withArgs(function (string $jiraKey, string $title, string $description, ?array $labels): bool {
                sort($labels);

                return $jiraKey === 'ABC-1'
                    && $title === 'Linear issue'
                    && $description === 'Body'
                    && $labels === ['existing-label', 'incoming-label'];
            });
        $jiraService->shouldReceive('transitionIssue')
            ->once()
            ->with('ABC-1', 'Готово');
        $lockService->shouldReceive('clearExpired')
            ->once();

        $job = new ProcessLinearEventJob(
            'update',
            'Issue',
            [
                'id' => 'lin-1',
                'title' => 'Linear issue',
                'description' => 'Body',
                'state' => ['id' => 'state-new', 'name' => 'In Review'],
                'team' => ['id' => 'team-1'],
                'labels' => [
                    'nodes' => [
                        ['name' => 'incoming-label'],
                    ],
                ],
            ],
            [
                'stateId' => 'state-old',
            ]
        );

        $job->handle($jiraService, $lockService);
    }

    public function test_it_does_not_transition_status_when_updated_from_has_no_state(): void
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
            'jira_issue_key' => 'ABC-2',
            'linear_issue_id' => 'lin-2',
            'linear_issue_identifier' => 'LIN-2',
        ]);

        $jiraService = Mockery::mock(JiraService::class);
        $lockService = Mockery::mock(SyncLockService::class);

        $lockService->shouldReceive('isLocked')
            ->once()
            ->with('jira', 'lin-2')
            ->andReturn(false);
        $jiraService->shouldReceive('updateIssue')
            ->once()
            ->with('ABC-2', 'Linear issue', 'Body', null);
        $lockService->shouldReceive('lock')
            ->once()
            ->with('linear', 'ABC-2');
        $jiraService->shouldNotReceive('transitionIssue');
        $lockService->shouldReceive('clearExpired')
            ->once();

        $job = new ProcessLinearEventJob(
            'update',
            'Issue',
            [
                'id' => 'lin-2',
                'title' => 'Linear issue',
                'description' => 'Body',
                'state' => ['name' => 'Canceled'],
                'team' => ['id' => 'team-1'],
            ],
            []
        );

        $job->handle($jiraService, $lockService);
    }
}
