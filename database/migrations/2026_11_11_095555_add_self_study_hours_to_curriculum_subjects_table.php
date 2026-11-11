<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->unsignedSmallInteger('auditorium_hours')->nullable();
            $table->unsignedSmallInteger('lecture_hours')->nullable();
            $table->unsignedSmallInteger('exercise_hours')->nullable();
            $table->unsignedSmallInteger('self_study_hours')->nullable();
            $table->string('control_type', 30)->nullable();
        });

        foreach (DB::table('curriculum_subjects')->orderBy('id')->get() as $curriculumSubject) {
            $sourceData = json_decode((string) $curriculumSubject->source_data, true);
            $legacyValues = is_array($sourceData) ? ($sourceData['legacy_plan_values'] ?? []) : [];
            $subject = DB::table('subjects')->where('id', $curriculumSubject->subject_id)->first();

            DB::table('curriculum_subjects')->where('id', $curriculumSubject->id)->update([
                'auditorium_hours' => $legacyValues['auditorium_hours'] ?? $subject?->auditorium_hours,
                'lecture_hours' => $legacyValues['lecture_hours'] ?? $subject?->lecture_hours,
                'exercise_hours' => $legacyValues['exercise_hours'] ?? $subject?->exercise_hours,
                'self_study_hours' => $legacyValues['self_study_hours'] ?? $subject?->self_study_hours,
                'control_type' => $legacyValues['control_type'] ?? $subject?->control_type,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->dropColumn([
                'auditorium_hours',
                'lecture_hours',
                'exercise_hours',
                'self_study_hours',
                'control_type',
            ]);
        });
    }
};
