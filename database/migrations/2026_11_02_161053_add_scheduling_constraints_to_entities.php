<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->unsignedSmallInteger('weekly_workload_hours')->default(0);
            $table->json('unavailable_weekdays')->nullable();
            $table->json('preferred_weekdays')->nullable();
            $table->json('unavailable_periods')->nullable();
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->json('equipment')->nullable();
            $table->json('unavailable_periods')->nullable();
        });

        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->json('cycle_weeks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->dropColumn('cycle_weeks');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['equipment', 'unavailable_periods']);
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn([
                'weekly_workload_hours',
                'unavailable_weekdays',
                'preferred_weekdays',
                'unavailable_periods',
            ]);
        });
    }
};
