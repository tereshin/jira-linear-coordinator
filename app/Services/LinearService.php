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
        $this->apiKey = config('services.linear.api_key');
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

    public function createIssue(string $teamId, string $title, string $description, ?string $stateId = null): array
    {
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

        $data = $this->query($mutation, ['input' => $input]);

        return $data['issueCreate']['issue'] ?? [];
    }

    public function updateIssue(string $linearId, string $title, string $description, ?string $stateId = null): void
    {
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
