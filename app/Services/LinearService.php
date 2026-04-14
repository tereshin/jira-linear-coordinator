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
