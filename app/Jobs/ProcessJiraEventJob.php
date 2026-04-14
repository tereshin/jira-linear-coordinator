<?php

namespace App\Jobs;

use App\Models\IssueMapping;
use App\Models\ProjectMapping;
use App\Models\SyncedAttachment;
use App\Services\JiraService;
use App\Services\LinearService;
use App\Services\SyncLockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessJiraEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $webhookEvent,
        private readonly array  $issue,
        private readonly array  $changelog = []
    ) {}

    public function handle(LinearService $linearService, SyncLockService $lockService, JiraService $jiraService): void
    {
        $jiraKey        = $this->issue['key'] ?? null;
        $fields         = $this->issue['fields'] ?? [];
        $title          = $fields['summary'] ?? '';
        $description    = $jiraService->jiraDescriptionToLinearText($fields['description'] ?? '');
        $statusName     = $this->resolveJiraStatusName($fields, $this->changelog);
        $jiraLabelNames = $this->resolveJiraLabelNamesForSync($fields, $this->changelog);

        if (!$jiraKey) {
            Log::warning('ProcessJiraEventJob: missing issue key');
            return;
        }

        if ($statusName !== null && strcasecmp($statusName, 'Draft') === 0) {
            Log::info('ProcessJiraEventJob: skipping Draft issue', ['jiraKey' => $jiraKey]);
            return;
        }

        $jiraProjectKey = explode('-', $jiraKey)[0];

        $projectMapping = ProjectMapping::active()
            ->where('jira_project_key', $jiraProjectKey)
            ->first();

        if (!$projectMapping) {
            Log::warning('ProcessJiraEventJob: no active mapping for project', ['project' => $jiraProjectKey]);
            return;
        }

        // Echo protection: skip if this was triggered by a Linear → Jira sync
        if ($lockService->isLocked('linear', $jiraKey)) {
            Log::info('ProcessJiraEventJob: echo lock active, skipping', ['jiraKey' => $jiraKey]);
            return;
        }

        $issueMapping = IssueMapping::where('jira_issue_key', $jiraKey)->first();

        $linearLabelIds = null;
        if ($jiraLabelNames !== null) {
            $teamLabelMap   = $linearService->getTeamLabelNameToIdMap($projectMapping->linear_team_id);
            $linearLabelIds = $linearService->jiraLabelNamesToExistingLinearLabelIds($jiraLabelNames, $teamLabelMap);
            if ($linearLabelIds === []) {
                $linearLabelIds = null;
            }
        }

        $linearIssueId = null;
        if ($issueMapping) {
            $linearStateId = null;
            if ($statusName) {
                $mappedStatus  = config('sync.status_map.jira_to_linear.' . $statusName, $statusName);
                $linearStateId = $linearService->getStateIdByName($projectMapping->linear_team_id, $mappedStatus);
                if ($linearStateId === null) {
                    Log::warning('ProcessJiraEventJob: no Linear workflow state for Jira status', [
                        'jiraKey'       => $jiraKey,
                        'jiraStatus'    => $statusName,
                        'mappedStatus'  => $mappedStatus,
                        'linearTeamId'  => $projectMapping->linear_team_id,
                    ]);
                }
            }

            if ($linearLabelIds !== null) {
                $existingLinearLabelIds = $linearService->getIssueLabelIds($issueMapping->linear_issue_id);
                $linearLabelIds = $this->mergeUniqueStrings($existingLinearLabelIds, $linearLabelIds);
                if ($linearLabelIds === []) {
                    $linearLabelIds = null;
                }
            }

            $lockService->lock('jira', $issueMapping->linear_issue_id);
            $linearService->updateIssue($issueMapping->linear_issue_id, $title, $description, $linearStateId, $linearLabelIds);
            $linearIssueId = $issueMapping->linear_issue_id;
        } else {
            $linearStateId = null;
            if ($statusName) {
                $mappedStatus  = config('sync.status_map.jira_to_linear.' . $statusName, $statusName);
                $linearStateId = $linearService->getStateIdByName($projectMapping->linear_team_id, $mappedStatus);
                if ($linearStateId === null) {
                    Log::warning('ProcessJiraEventJob: no Linear workflow state for Jira status (create path)', [
                        'jiraKey'       => $jiraKey,
                        'jiraStatus'    => $statusName,
                        'mappedStatus'  => $mappedStatus,
                        'linearTeamId'  => $projectMapping->linear_team_id,
                    ]);
                }
            }

            if ($linearStateId === null) {
                $linearStateId = $linearService->getStateIdForIssueCreatedFromJira($projectMapping->linear_team_id);
            }

            $linearIssueId = DB::transaction(function () use ($projectMapping, $jiraKey, $title, $description, $linearStateId, $linearLabelIds, $linearService) {
                $linearIssue = $linearService->createIssue(
                    $projectMapping->linear_team_id,
                    $title,
                    $description,
                    $linearStateId,
                    $projectMapping->linear_project_id,
                    $linearLabelIds
                );

                IssueMapping::updateOrCreate(
                    ['jira_issue_key' => $jiraKey],
                    [
                        'project_mapping_id'      => $projectMapping->id,
                        'linear_issue_id'         => $linearIssue['id'],
                        'linear_issue_identifier' => $linearIssue['identifier'],
                    ]
                );

                return $linearIssue['id'] ?? null;
            });
        }

        if (is_string($linearIssueId) && $linearIssueId !== '') {
            $this->syncJiraAttachmentsToLinear($jiraKey, $linearIssueId, $jiraService, $linearService);
        }

        $lockService->clearExpired();
    }

    /**
     * Prefer issue.fields.status; fall back to native webhook changelog items when status is omitted.
     */
    private function resolveJiraStatusName(array $fields, array $changelog): ?string
    {
        $fromIssue = $fields['status']['name'] ?? null;
        if (is_string($fromIssue) && $fromIssue !== '') {
            return $fromIssue;
        }

        $resolved = null;
        foreach ($changelog['items'] ?? [] as $item) {
            $field   = $item['field'] ?? '';
            $fieldId = $item['fieldId'] ?? '';
            if (strcasecmp((string) $field, 'status') === 0 || $fieldId === 'status') {
                $to = $item['toString'] ?? null;
                if (is_string($to) && $to !== '') {
                    $resolved = $to;
                }
            }
        }

        return $resolved;
    }

    /**
     * @return array<int, string>|null null = do not change Linear labels; array = full set of Jira label names (possibly empty)
     */
    private function resolveJiraLabelNamesForSync(array $fields, array $changelog): ?array
    {
        if (array_key_exists('labels', $fields)) {
            $labels = $fields['labels'];
            $labels = is_array($labels) ? $labels : [];
            $out    = [];
            foreach ($labels as $label) {
                if (is_string($label) && $label !== '') {
                    $out[] = $label;
                }
            }

            return array_values(array_unique($out));
        }

        $fromChangelog = [];
        foreach ($changelog['items'] ?? [] as $item) {
            $field   = $item['field'] ?? '';
            $fieldId = $item['fieldId'] ?? '';
            if (strcasecmp((string) $field, 'labels') === 0 || $fieldId === 'labels') {
                $to = $item['toString'] ?? null;
                if (is_string($to) && $to !== '') {
                    $fromChangelog = array_merge($fromChangelog, $this->parseJiraLabelsString($to));
                }
            }
        }

        if ($fromChangelog !== []) {
            return array_values(array_unique($fromChangelog));
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function parseJiraLabelsString(string $value): array
    {
        $parts = preg_split('/\s*,\s*|\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        return $parts ?: [];
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

    private function syncJiraAttachmentsToLinear(
        string $jiraKey,
        string $linearIssueId,
        JiraService $jiraService,
        LinearService $linearService
    ): void {
        $attachments = $jiraService->getIssueAttachments($jiraKey);
        foreach ($attachments as $attachment) {
            $jiraAttachmentId = $attachment['id'] ?? '';
            $attachmentUrl = $attachment['content'] ?? '';
            $filename = $attachment['filename'] ?? '';
            $mimeType = $attachment['mimeType'] ?? 'application/octet-stream';

            if (!is_string($jiraAttachmentId) || $jiraAttachmentId === '' || !is_string($attachmentUrl) || $attachmentUrl === '') {
                continue;
            }

            $isAlreadySynced = SyncedAttachment::query()
                ->where('jira_attachment_id', $jiraAttachmentId)
                ->where('linear_issue_id', $linearIssueId)
                ->exists();

            if ($isAlreadySynced) {
                continue;
            }

            try {
                $downloaded = $jiraService->downloadAttachment($attachmentUrl);
                $uploaded = $linearService->uploadBinaryAttachment(
                    $linearIssueId,
                    is_string($filename) && $filename !== '' ? $filename : ('attachment-' . $jiraAttachmentId),
                    is_string($mimeType) && $mimeType !== '' ? $mimeType : ($downloaded['contentType'] ?? 'application/octet-stream'),
                    $downloaded['content'] ?? '',
                    is_string($filename) && $filename !== '' ? $filename : null
                );

                SyncedAttachment::create([
                    'jira_attachment_id' => $jiraAttachmentId,
                    'jira_issue_key' => $jiraKey,
                    'linear_issue_id' => $linearIssueId,
                    'linear_attachment_id' => is_string($uploaded['id'] ?? null) ? $uploaded['id'] : null,
                    'linear_asset_url' => is_string($uploaded['url'] ?? null) ? $uploaded['url'] : null,
                    'filename' => is_string($filename) ? $filename : null,
                    'mime_type' => is_string($mimeType) ? $mimeType : null,
                ]);
            } catch (Throwable $exception) {
                Log::warning('ProcessJiraEventJob: failed syncing Jira attachment to Linear', [
                    'jiraKey' => $jiraKey,
                    'jiraAttachmentId' => $jiraAttachmentId,
                    'linearIssueId' => $linearIssueId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
