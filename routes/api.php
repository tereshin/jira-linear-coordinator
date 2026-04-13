<?php

use App\Http\Controllers\Webhooks\JiraWebhookController;
use App\Http\Controllers\Webhooks\LinearWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/jira', [JiraWebhookController::class, 'handle']);
Route::post('/webhooks/linear', [LinearWebhookController::class, 'handle']);
