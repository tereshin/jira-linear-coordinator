<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JiraService
{
    private string $baseUrl;
    private string $email;
    private string $apiToken;

    public function __construct()
    {
        $this->baseUrl  = rtrim(config('services.jira.base_url'), '/');
        $this->email    = config('services.jira.email');
        $this->apiToken = config('services.jira.api_token');
    }

    private function http()
    {
        return Http::withBasicAuth($this->email, $this->apiToken)
            ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json']);
    }

    public function createIssue(string $projectKey, string $title, string $description): array
    {
        $response = $this->http()->post("{$this->baseUrl}/rest/api/3/issue", [
            'fields' => [
                'project'     => ['key' => $projectKey],
                'summary'     => $title,
                'description' => [
                    'type'    => 'doc',
                    'version' => 1,
                    'content' => [
                        [
                            'type'    => 'paragraph',
                            'content' => [['type' => 'text', 'text' => $description]],
                        ],
                    ],
                ],
                'issuetype' => ['name' => 'Task'],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Jira createIssue failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Jira createIssue failed: ' . $response->body());
        }

        return $response->json();
    }

    public function updateIssue(string $jiraKey, string $title, string $description): void
    {
        $response = $this->http()->put("{$this->baseUrl}/rest/api/3/issue/{$jiraKey}", [
            'fields' => [
                'summary'     => $title,
                'description' => [
                    'type'    => 'doc',
                    'version' => 1,
                    'content' => [
                        [
                            'type'    => 'paragraph',
                            'content' => [['type' => 'text', 'text' => $description]],
                        ],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Jira updateIssue failed', ['key' => $jiraKey, 'status' => $response->status()]);
            throw new \RuntimeException('Jira updateIssue failed: ' . $response->body());
        }
    }

    public function getTransitions(string $jiraKey): array
    {
        $response = $this->http()->get("{$this->baseUrl}/rest/api/3/issue/{$jiraKey}/transitions");

        if ($response->failed()) {
            Log::error('Jira getTransitions failed', ['key' => $jiraKey]);
            return [];
        }

        return $response->json('transitions', []);
    }

    public function transitionIssue(string $jiraKey, string $statusName): void
    {
        $transitions = $this->getTransitions($jiraKey);

        $transition = collect($transitions)->first(
            fn($t) => strcasecmp($t['to']['name'] ?? '', $statusName) === 0
        );

        if (!$transition) {
            Log::warning('Jira transitionIssue: no matching transition', [
                'key'    => $jiraKey,
                'status' => $statusName,
            ]);
            return;
        }

        $response = $this->http()->post("{$this->baseUrl}/rest/api/3/issue/{$jiraKey}/transitions", [
            'transition' => ['id' => $transition['id']],
        ]);

        if ($response->failed()) {
            Log::error('Jira transitionIssue failed', ['key' => $jiraKey, 'status' => $response->status()]);
            throw new \RuntimeException('Jira transitionIssue failed: ' . $response->body());
        }
    }

    public function getAllIssues(string $projectKey): array
    {
        $issues   = [];
        $startAt  = 0;
        $maxResults = 100;

        do {
            $response = $this->http()->get("{$this->baseUrl}/rest/api/3/search", [
                'jql'        => "project={$projectKey}",
                'startAt'    => $startAt,
                'maxResults' => $maxResults,
                'fields'     => 'summary,description,status',
            ]);

            if ($response->failed()) {
                Log::error('Jira getAllIssues failed', ['project' => $projectKey]);
                break;
            }

            $data   = $response->json();
            $page   = $data['issues'] ?? [];
            $total  = $data['total'] ?? 0;
            $issues = array_merge($issues, $page);
            $startAt += $maxResults;
        } while ($startAt < $total);

        return $issues;
    }
}
