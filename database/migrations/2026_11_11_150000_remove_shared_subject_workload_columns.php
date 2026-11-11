<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('curriculum_subjects')->orderBy('id')->get() as $curriculumSubject) {
            $subject = DB::table('subjects')->where('id', $curriculumSubject->subject_id)->first();
            if ($subject === null) {
                continue;
            }

            DB::table('curriculum_subjects')
                ->where('id', $curriculumSubject->id)
                ->update([
                    'auditorium_hours' => $curriculumSubject->auditorium_hours ?? $subject->auditorium_hours,
                    'lecture_hours' => $curriculumSubject->lecture_hours ?? $subject->lecture_hours,
                    'exercise_hours' => $curriculumSubject->exercise_hours ?? $subject->exercise_hours,
                    'self_study_hours' => $curriculumSubject->self_study_hours ?? $subject->self_study_hours,
                    'control_type' => $curriculumSubject->control_type ?? $subject->control_type,
                ]);
        }

        Schema::table('subjects', function (Blueprint $table): void {
            $table->dropColumn([
                'auditorium_hours',
                'lecture_hours',
                'exercise_hours',
                'self_study_hours',
                'control_type',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table): void {
            $table->unsignedSmallInteger('auditorium_hours')->default(0);
            $table->unsignedSmallInteger('lecture_hours')->default(0);
            $table->unsignedSmallInteger('exercise_hours')->default(0);
            $table->unsignedSmallInteger('self_study_hours')->default(0);
            $table->string('control_type', 30)->nullable();
        });

        foreach (DB::table('subjects')->orderBy('id')->get() as $subject) {
            $values = DB::table('curriculum_subjects')
                ->where('subject_id', $subject->id)
                ->orderBy('id')
                ->first();

            if ($values !== null) {
                DB::table('subjects')->where('id', $subject->id)->update([
                    'auditorium_hours' => $values->auditorium_hours ?? 0,
                    'lecture_hours' => $values->lecture_hours ?? 0,
                    'exercise_hours' => $values->exercise_hours ?? 0,
                    'self_study_hours' => $values->self_study_hours ?? 0,
                    'control_type' => $values->control_type,
                ]);
            }
        }
    }
};
