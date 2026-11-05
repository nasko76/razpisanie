<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->unsignedSmallInteger('lecture_scheduled_hours')->nullable();
            $table->unsignedSmallInteger('exercise_scheduled_hours')->nullable();
        });

        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->string('coverage_type', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->dropColumn('coverage_type');
        });

        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->dropColumn(['lecture_scheduled_hours', 'exercise_scheduled_hours']);
        });
    }
};
