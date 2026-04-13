<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('jira_project_key')->unique();
            $table->string('linear_team_id')->unique();
            $table->string('linear_team_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_mappings');
    }
};
