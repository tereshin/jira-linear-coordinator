<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLinearEventJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LinearWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $rawBody = $request->getContent();
        $secret  = config('services.linear.webhook_secret');

        if ($secret) {
            $expected = hash_hmac('sha256', $rawBody, $secret);
            $received = $request->header('Linear-Signature', '');

            if (!hash_equals($expected, $received)) {
                abort(401, 'Invalid signature');
            }
        }

        $payload = $request->json()->all();
        $action  = $payload['action'] ?? '';
        $type    = $payload['type'] ?? '';
        $data    = $payload['data'] ?? [];

        if (!in_array($action, ['create', 'update'], true) || $type !== 'Issue') {
            return response('Event not supported', 200);
        }

        if (empty($data)) {
            return response('No data', 200);
        }

        $updatedFrom = $payload['updatedFrom'] ?? [];

        ProcessLinearEventJob::dispatch($action, $type, $data, $updatedFrom);

        return response('OK', 200);
    }
}
