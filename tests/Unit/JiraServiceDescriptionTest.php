<?php

namespace Tests\Unit;

use App\Services\JiraService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JiraServiceDescriptionTest extends TestCase
{
    public function test_it_builds_multiline_adf_when_updating_issue(): void
    {
        config([
            'services.jira.base_url' => 'https://jira.example.com',
            'services.jira.email' => 'user@example.com',
            'services.jira.api_token' => 'token',
        ]);

        $capturedPayload = [];

        Http::fake(function (Request $request) use (&$capturedPayload) {
            $capturedPayload = $request->data();
            return Http::response([], 204);
        });

        $service = new JiraService();
        $service->updateIssue('ABC-1', 'Title', "Line one\nLine two");

        $content = $capturedPayload['fields']['description']['content'] ?? [];

        $this->assertCount(2, $content);
        $this->assertSame('Line one', $content[0]['content'][0]['text'] ?? null);
        $this->assertSame('Line two', $content[1]['content'][0]['text'] ?? null);
    }

    public function test_it_converts_jira_adf_to_linear_text(): void
    {
        $service = new JiraService();

        $adf = [
            'type' => 'doc',
            'version' => 1,
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'First paragraph'],
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Second paragraph'],
                    ],
                ],
            ],
        ];

        $this->assertSame("First paragraph\n\nSecond paragraph", $service->jiraDescriptionToLinearText($adf));
    }
}
