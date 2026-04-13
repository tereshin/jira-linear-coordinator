<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_mapping_id')->constrained('project_mappings')->cascadeOnDelete();
            $table->string('jira_issue_key')->unique();
            $table->string('linear_issue_id')->unique();
            $table->string('linear_issue_identifier');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_mappings');
    }
};
