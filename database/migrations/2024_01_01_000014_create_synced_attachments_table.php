<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synced_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('jira_attachment_id');
            $table->string('jira_issue_key');
            $table->string('linear_issue_id');
            $table->string('linear_attachment_id')->nullable();
            $table->string('linear_asset_url')->nullable();
            $table->string('filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();

            $table->unique(['jira_attachment_id', 'linear_issue_id']);
            $table->index(['jira_issue_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synced_attachments');
    }
};
