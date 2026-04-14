<?php

namespace Tests\Feature;

use App\Jobs\ProcessJiraEventJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JiraWebhookControllerSignatureTest extends TestCase
{
    public function test_it_rejects_webhook_when_header_does_not_match_secret(): void
    {
        config(['services.jira.webhook_secret' => 'expected-secret']);
        Queue::fake();

        $response = $this->postJson('/api/webhooks/jira', [
            'key' => 'ABC-1',
            'fields' => [
                'summary' => 'Example issue',
                'status' => ['name' => 'Todo'],
            ],
        ], [
            'X-Hub-Signature' => 'wrong-secret',
        ]);

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_it_accepts_webhook_when_header_matches_secret_as_plain_string(): void
    {
        config(['services.jira.webhook_secret' => 'expected-secret']);
        Queue::fake();

        $response = $this->postJson('/api/webhooks/jira', [
            'key' => 'ABC-1',
            'fields' => [
                'summary' => 'Example issue',
                'status' => ['name' => 'Todo'],
            ],
        ], [
            'X-Hub-Signature' => 'expected-secret',
        ]);

        $response->assertOk();
        Queue::assertPushed(ProcessJiraEventJob::class);
    }
}
