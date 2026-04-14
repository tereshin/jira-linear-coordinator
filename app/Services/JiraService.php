<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JiraService
{
    private string $baseUrl;
    private string $email;
    private string $apiToken;

    public function __construct()
    {
        $this->baseUrl  = rtrim(config('services.jira.base_url') ?? '', '/');
        $this->email    = config('services.jira.email') ?? '';
        $this->apiToken = config('services.jira.api_token') ?? '';
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->email, $this->apiToken)
            ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json']);
    }

    private function binaryHttp(): PendingRequest
    {
        return Http::withBasicAuth($this->email, $this->apiToken);
    }

    public function createIssue(string $projectKey, string $title, string $description, ?array $labels = null): array
    {
        $fields = [
            'project'     => ['key' => $projectKey],
            'summary'     => $title,
            'description' => $this->buildAdfDescription($description),
            'issuetype' => ['name' => 'Task'],
        ];

        if ($labels !== null) {
            $fields['labels'] = array_values($labels);
        }

        $response = $this->http()->post("{$this->baseUrl}/rest/api/3/issue", [
            'fields' => $fields,
        ]);

        if ($response->failed()) {
            Log::error('Jira createIssue failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Jira createIssue failed: ' . $response->body());
        }

        return $response->json();
    }

    public function updateIssue(string $jiraKey, string $title, string $description, ?array $labels = null): void
    {
        $fields = [
            'summary'     => $title,
            'description' => $this->buildAdfDescription($description),
        ];

        if ($labels !== null) {
            $fields['labels'] = array_values($labels);
        }

        $response = $this->http()->put("{$this->baseUrl}/rest/api/3/issue/{$jiraKey}", [
            'fields' => $fields,
        ]);

        if ($response->failed()) {
            Log::error('Jira updateIssue failed', ['key' => $jiraKey, 'status' => $response->status()]);
            throw new \RuntimeException('Jira updateIssue failed: ' . $response->body());
        }
    }

    /**
     * @return array<int, string>
     */
    public function getIssueLabels(string $jiraKey): array
    {
        $fields = $this->getIssueFields($jiraKey, 'labels');
        $labels = $fields['labels'] ?? [];
        if (!is_array($labels)) {
            return [];
        }

        $names = [];
        foreach ($labels as $label) {
            if (is_string($label) && $label !== '') {
                $names[] = $label;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<int, array{id: string, content: string, filename: string, mimeType: string}>
     */
    public function getIssueAttachments(string $jiraKey): array
    {
        $fields = $this->getIssueFields($jiraKey, 'attachment');
        $attachments = $fields['attachment'] ?? [];
        if (!is_array($attachments)) {
            return [];
        }

        $out = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $id = (string) ($attachment['id'] ?? '');
            $content = (string) ($attachment['content'] ?? '');
            if ($id === '' || $content === '') {
                continue;
            }

            $filename = (string) ($attachment['filename'] ?? '');
            $mimeType = (string) ($attachment['mimeType'] ?? '');

            $out[] = [
                'id' => $id,
                'content' => $content,
                'filename' => $filename !== '' ? $filename : ('attachment-' . $id),
                'mimeType' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            ];
        }

        return $out;
    }

    /**
     * @return array{content: string, contentType: string}
     */
    public function downloadAttachment(string $attachmentUrl): array
    {
        $response = $this->binaryHttp()->send('GET', $attachmentUrl);

        if ($response->failed()) {
            Log::error('Jira downloadAttachment failed', [
                'url' => $attachmentUrl,
                'status' => $response->status(),
            ]);
            throw new \RuntimeException('Jira downloadAttachment failed: ' . $response->body());
        }

        $contentType = $response->header('Content-Type') ?: 'application/octet-stream';

        return [
            'content' => $response->body(),
            'contentType' => $contentType,
        ];
    }

    public function jiraDescriptionToLinearText(mixed $description): string
    {
        if (is_string($description)) {
            return $description;
        }

        if (!is_array($description)) {
            return '';
        }

        $rendered = $this->renderAdfNode($description);
        $rendered = preg_replace("/\n{3,}/", "\n\n", $rendered);

        return trim((string) $rendered);
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
                'fields'     => 'summary,description,status,labels',
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

    /**
     * @return array{type: string, version: int, content: array<int, array<string, mixed>>}
     */
    private function buildAdfDescription(?string $description): array
    {
        $normalized = is_string($description) ? str_replace(["\r\n", "\r"], "\n", $description) : '';
        $lines = explode("\n", $normalized);

        if ($lines === ['']) {
            $lines = [];
        }

        $content = [];
        foreach ($lines as $line) {
            if ($line === '') {
                $content[] = [
                    'type' => 'paragraph',
                    'content' => [],
                ];
                continue;
            }

            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => $line],
                ],
            ];
        }

        if ($content === []) {
            $content[] = [
                'type' => 'paragraph',
                'content' => [],
            ];
        }

        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $content,
        ];
    }

    private function renderAdfNode(mixed $node): string
    {
        if (!is_array($node)) {
            return '';
        }

        $type = $node['type'] ?? null;
        if ($type === 'text') {
            return (string) ($node['text'] ?? '');
        }

        if ($type === 'hardBreak') {
            return "\n";
        }

        if ($type === 'paragraph' || $type === 'heading') {
            return $this->renderAdfChildren($node) . "\n\n";
        }

        if ($type === 'bulletList' || $type === 'orderedList') {
            $children = $node['content'] ?? [];
            if (!is_array($children)) {
                return '';
            }

            $items = [];
            foreach ($children as $index => $child) {
                $itemText = trim($this->renderAdfNode($child));
                if ($itemText === '') {
                    continue;
                }

                if ($type === 'orderedList') {
                    $items[] = ($index + 1) . '. ' . $itemText;
                } else {
                    $items[] = '- ' . $itemText;
                }
            }

            return ($items === []) ? '' : (implode("\n", $items) . "\n\n");
        }

        if ($type === 'listItem') {
            return $this->renderAdfChildren($node);
        }

        if ($type === 'codeBlock') {
            $content = $this->renderAdfChildren($node);
            return "```\n" . rtrim($content, "\n") . "\n```\n\n";
        }

        return $this->renderAdfChildren($node);
    }

    private function renderAdfChildren(array $node): string
    {
        $children = $node['content'] ?? [];
        if (!is_array($children)) {
            return '';
        }

        $out = '';
        foreach ($children as $child) {
            $out .= $this->renderAdfNode($child);
        }

        return $out;
    }

    private function getIssueFields(string $jiraKey, string $fieldList): array
    {
        $response = $this->http()->get("{$this->baseUrl}/rest/api/3/issue/{$jiraKey}", [
            'fields' => $fieldList,
        ]);

        if ($response->failed()) {
            Log::warning('Jira getIssueFields failed', [
                'key' => $jiraKey,
                'fields' => $fieldList,
                'status' => $response->status(),
            ]);
            return [];
        }

        $fields = $response->json('fields', []);

        return is_array($fields) ? $fields : [];
    }
}
