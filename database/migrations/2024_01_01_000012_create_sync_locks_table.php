<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_locks', function (Blueprint $table) {
            $table->id();
            $table->enum('source', ['jira', 'linear']);
            $table->string('issue_ref');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['source', 'issue_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_locks');
    }
};
