<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessJiraEventJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class JiraWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        try {
            $rawBody = $request->getContent();
            $secret  = config('services.jira.webhook_secret');

            if ($secret) {
                $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
                $received = $request->header('X-Hub-Signature', '');

                if (!hash_equals($expected, $received)) {
                    Log::error('Jira webhook: invalid signature', [
                        'expected' => $expected,
                        'received' => $received,
                        'ip' => $request->ip(),
                        'headers' => $request->headers->all(),
                        'body' => $rawBody,
                    ]);

                    return response('Invalid signature', 401);
                }
            }

            $payload = $request->json()->all();

            if (empty($payload)) {
                Log::error('Jira webhook: empty or invalid JSON payload', [
                    'ip' => $request->ip(),
                    'headers' => $request->headers->all(),
                    'body' => $rawBody,
                ]);

                return response('Invalid payload', 400);
            }

            // Native Jira webhooks send webhookEvent + issue + optional changelog.
            // Jira Automation "Send web request" often posts the issue JSON body only (no webhookEvent).
            $webhookEvent = $payload['webhookEvent'] ?? '';
            $issue        = $payload['issue'] ?? [];
            $changelog    = $payload['changelog'] ?? [];

            if ($webhookEvent === '') {
                if (($issue === [] || $issue === null) && isset($payload['key'], $payload['fields'])) {
                    $issue     = $payload;
                    $changelog = $issue['changelog'] ?? [];
                    unset($issue['changelog']);
                    $webhookEvent = 'jira:issue_updated';
                } elseif (!empty($issue)) {
                    $webhookEvent = 'jira:issue_updated';
                }
            }

            if (!in_array($webhookEvent, ['jira:issue_created', 'jira:issue_updated'], true)) {
                Log::warning('Jira webhook: unsupported event', [
                    'event' => $webhookEvent,
                    'payload' => $payload,
                ]);

                return response('Event ' . ($payload['webhookEvent'] ?? 'unknown') . ' not supported', 200);
            }

            if (empty($issue)) {
                Log::error('Jira webhook: issue data missing', [
                    'event' => $webhookEvent,
                    'payload' => $payload,
                ]);

                return response('No issue data', 200);
            }

            ProcessJiraEventJob::dispatch($webhookEvent, $issue, $changelog);

            Log::info('Jira webhook: job dispatched', [
                'event' => $webhookEvent,
                'issue_key' => $issue['key'] ?? null,
                'issue_id' => $issue['id'] ?? null,
            ]);

            return response('OK', 200);
        } catch (Throwable $e) {
            Log::error('Jira webhook: unhandled exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip(),
                'body' => $request->getContent(),
            ]);

            return response('Internal Server Error', 500);
        }
    }
}
