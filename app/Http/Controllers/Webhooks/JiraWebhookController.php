<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessJiraEventJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class JiraWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $rawBody  = $request->getContent();
        $secret   = config('services.jira.webhook_secret');

        if ($secret) {
            $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
            $received = $request->header('X-Hub-Signature', '');

            if (!hash_equals($expected, $received)) {
                abort(401, 'Invalid signature');
            }
        }

        $payload      = $request->json()->all();
        $webhookEvent = $payload['webhookEvent'] ?? '';
        $issue        = $payload['issue'] ?? [];
        $changelog    = $payload['changelog'] ?? [];

        if (!in_array($webhookEvent, ['jira:issue_created', 'jira:issue_updated'], true)) {
            return response('Event not supported', 200);
        }

        if (empty($issue)) {
            return response('No issue data', 200);
        }

        ProcessJiraEventJob::dispatch($webhookEvent, $issue, $changelog);

        return response('OK', 200);
    }
}
