<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinearService
{
    private string $apiKey;
    private string $endpoint = 'https://api.linear.app/graphql';

    public function __construct()
    {
        $this->apiKey = config('services.linear.api_key') ?? '';
    }

    private function query(string $query, array $variables = []): array
    {
        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type'  => 'application/json',
        ])->post($this->endpoint, [
            'query'     => $query,
            'variables' => $variables,
        ]);

        $body = $response->json();

        if (isset($body['errors'])) {
            Log::error('Linear GraphQL error', ['errors' => $body['errors']]);
            throw new \RuntimeException('Linear API error: ' . json_encode($body['errors']));
        }

        return $body['data'] ?? [];
    }

    public function createIssue(
        string $teamId,
        string $title,
        string $description,
        ?string $stateId = null,
        ?string $projectId = null,
        ?array $labelIds = null
    ): array {
        $mutation = <<<GQL
        mutation CreateIssue(\$input: IssueCreateInput!) {
            issueCreate(input: \$input) {
                success
                issue {
                    id
                    identifier
                    title
                }
            }
        }
        GQL;

        $input = [
            'teamId'      => $teamId,
            'title'       => $title,
            'description' => $description,
        ];

        if ($stateId) {
            $input['stateId'] = $stateId;
        }

        if ($projectId) {
            $input['projectId'] = $projectId;
        }

        if ($labelIds !== null) {
            $input['labelIds'] = array_values($labelIds);
        }

        $data = $this->query($mutation, ['input' => $input]);

        return $data['issueCreate']['issue'] ?? [];
    }

    public function updateIssue(
        string $linearId,
        string $title,
        string $description,
        ?string $stateId = null,
        ?array $labelIds = null
    ): void {
        $mutation = <<<GQL
        mutation UpdateIssue(\$id: String!, \$input: IssueUpdateInput!) {
            issueUpdate(id: \$id, input: \$input) {
                success
            }
        }
        GQL;

        $input = [
            'title'       => $title,
            'description' => $description,
        ];

        if ($stateId) {
            $input['stateId'] = $stateId;
        }

        if ($labelIds !== null) {
            $input['labelIds'] = array_values($labelIds);
        }

        $this->query($mutation, ['id' => $linearId, 'input' => $input]);
    }

    public function getStateIdByName(string $teamId, string $stateName): ?string
    {
        $query = <<<GQL
        query TeamStates(\$teamId: String!) {
            team(id: \$teamId) {
                states {
                    nodes {
                        id
                        name
                    }
                }
            }
        }
        GQL;

        $data = $this->query($query, ['teamId' => $teamId]);
        $states = $data['team']['states']['nodes'] ?? [];

        foreach ($states as $state) {
            if (strcasecmp($state['name'], $stateName) === 0) {
                return $state['id'];
            }
        }

        return null;
    }

    public function getStateIdForIssueCreatedFromJira(string $teamId): ?string
    {
        $stateName = config('sync.linear_state_on_create_from_jira', 'Backlog');
        if (!is_string($stateName) || $stateName === '') {
            $stateName = 'Backlog';
        }

        $stateId = $this->getStateIdByName($teamId, $stateName);
        if ($stateId === null) {
            Log::warning('LinearService: no workflow state for new issue from Jira', [
                'linearTeamId' => $teamId,
                'stateName'    => $stateName,
            ]);
        }

        return $stateId;
    }

    /**
     * Lowercase label name => Linear issue label id (team-scoped labels only).
     */
    public function getTeamLabelNameToIdMap(string $teamId): array
    {
        $query = <<<GQL
        query TeamIssueLabels(\$teamId: String!, \$after: String) {
            team(id: \$teamId) {
                labels(first: 250, after: \$after) {
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                    nodes {
                        id
                        name
                    }
                }
            }
        }
        GQL;

        $map = [];
        $after = null;

        do {
            $variables = ['teamId' => $teamId];
            if ($after) {
                $variables['after'] = $after;
            }

            $data     = $this->query($query, $variables);
            $team     = $data['team'] ?? [];
            $conn     = is_array($team) ? ($team['labels'] ?? []) : [];
            $nodes    = $conn['nodes'] ?? [];
            $pageInfo = $conn['pageInfo'] ?? [];

            foreach ($nodes as $node) {
                $name = $node['name'] ?? '';
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $map[strtolower($name)] = $node['id'];
            }

            $hasNextPage = $pageInfo['hasNextPage'] ?? false;
            $after       = $pageInfo['endCursor'] ?? null;
        } while ($hasNextPage && $after);

        return $map;
    }

    /**
     * @param  array<int, string>  $jiraLabelNames
     * @param  array<string, string>  $teamLabelMapLowerToId  from getTeamLabelNameToIdMap
     * @return array<int, string> Linear label ids (only labels that exist on the team)
     */
    public function jiraLabelNamesToExistingLinearLabelIds(array $jiraLabelNames, array $teamLabelMapLowerToId): array
    {
        $ids = [];
        foreach ($jiraLabelNames as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $key = strtolower($name);
            if (isset($teamLabelMapLowerToId[$key])) {
                $ids[$teamLabelMapLowerToId[$key]] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return array<int, string>
     */
    public function getIssueLabelIds(string $linearId): array
    {
        $query = <<<GQL
        query IssueLabels(\$id: String!) {
            issue(id: \$id) {
                labels {
                    nodes {
                        id
                    }
                }
            }
        }
        GQL;

        $data = $this->query($query, ['id' => $linearId]);
        $nodes = $data['issue']['labels']['nodes'] ?? [];
        if (!is_array($nodes)) {
            return [];
        }

        $ids = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $id = $node['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return array{id: string|null, url: string, title: string}
     */
    public function uploadBinaryAttachment(
        string $linearIssueId,
        string $filename,
        string $contentType,
        string $binaryContent,
        ?string $title = null
    ): array {
        $size = strlen($binaryContent);
        if ($size <= 0) {
            throw new \RuntimeException('Linear uploadBinaryAttachment failed: empty payload');
        }

        $mutation = <<<GQL
        mutation FileUpload(\$contentType: String!, \$filename: String!, \$size: Int!) {
            fileUpload(contentType: \$contentType, filename: \$filename, size: \$size) {
                success
                uploadFile {
                    uploadUrl
                    assetUrl
                    headers {
                        key
                        value
                    }
                }
            }
        }
        GQL;

        $data = $this->query($mutation, [
            'contentType' => $contentType,
            'filename' => $filename,
            'size' => $size,
        ]);

        $uploadInfo = $data['fileUpload']['uploadFile'] ?? null;
        if (!is_array($uploadInfo)) {
            throw new \RuntimeException('Linear uploadBinaryAttachment failed: upload URL not returned');
        }

        $uploadUrl = $uploadInfo['uploadUrl'] ?? '';
        $assetUrl = $uploadInfo['assetUrl'] ?? '';
        if (!is_string($uploadUrl) || $uploadUrl === '' || !is_string($assetUrl) || $assetUrl === '') {
            throw new \RuntimeException('Linear uploadBinaryAttachment failed: invalid upload payload');
        }

        $headers = [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=31536000',
        ];

        $headerPairs = $uploadInfo['headers'] ?? [];
        if (is_array($headerPairs)) {
            foreach ($headerPairs as $header) {
                if (!is_array($header)) {
                    continue;
                }

                $key = $header['key'] ?? null;
                $value = $header['value'] ?? null;
                if (is_string($key) && $key !== '' && is_string($value)) {
                    $headers[$key] = $value;
                }
            }
        }

        $uploadResponse = Http::withHeaders($headers)->send('PUT', $uploadUrl, [
            'body' => $binaryContent,
        ]);

        if ($uploadResponse->failed()) {
            Log::error('Linear uploadBinaryAttachment failed at PUT stage', [
                'status' => $uploadResponse->status(),
                'issueId' => $linearIssueId,
                'filename' => $filename,
            ]);
            throw new \RuntimeException('Linear uploadBinaryAttachment failed: ' . $uploadResponse->body());
        }

        $attachmentMutation = <<<GQL
        mutation AttachmentCreate(\$input: AttachmentCreateInput!) {
            attachmentCreate(input: \$input) {
                success
                attachment {
                    id
                    url
                    title
                }
            }
        }
        GQL;

        $attachmentInput = [
            'issueId' => $linearIssueId,
            'title' => $title ?: $filename,
            'url' => $assetUrl,
        ];

        $attachmentData = $this->query($attachmentMutation, ['input' => $attachmentInput]);
        $attachment = $attachmentData['attachmentCreate']['attachment'] ?? [];

        return [
            'id' => is_array($attachment) ? ($attachment['id'] ?? null) : null,
            'url' => is_array($attachment) ? ($attachment['url'] ?? $assetUrl) : $assetUrl,
            'title' => is_array($attachment) ? ($attachment['title'] ?? ($title ?: $filename)) : ($title ?: $filename),
        ];
    }

    public function getAllIssues(string $teamId): array
    {
        $query = <<<GQL
        query TeamIssues(\$teamId: String!, \$after: String) {
            team(id: \$teamId) {
                issues(first: 100, after: \$after) {
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                    nodes {
                        id
                        identifier
                        title
                        description
                        state {
                            id
                            name
                        }
                        labels {
                            nodes {
                                id
                                name
                            }
                        }
                    }
                }
            }
        }
        GQL;

        $issues = [];
        $after  = null;

        do {
            $variables = ['teamId' => $teamId];
            if ($after) {
                $variables['after'] = $after;
            }

            $data     = $this->query($query, $variables);
            $page     = $data['team']['issues'] ?? [];
            $nodes    = $page['nodes'] ?? [];
            $pageInfo = $page['pageInfo'] ?? [];

            $issues = array_merge($issues, $nodes);

            $hasNextPage = $pageInfo['hasNextPage'] ?? false;
            $after       = $pageInfo['endCursor'] ?? null;
        } while ($hasNextPage && $after);

        return $issues;
    }
}
