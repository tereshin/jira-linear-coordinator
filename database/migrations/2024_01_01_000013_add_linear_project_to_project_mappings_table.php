<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_mappings', function (Blueprint $table) {
            $table->dropUnique(['linear_team_id']);
            $table->string('linear_project_id')->nullable()->unique()->after('linear_team_id');
            $table->string('linear_project_name')->nullable()->after('linear_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_mappings', function (Blueprint $table) {
            $table->dropUnique(['linear_project_id']);
            $table->dropColumn(['linear_project_id', 'linear_project_name']);
            $table->unique('linear_team_id');
        });
    }
};
