<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->unsignedSmallInteger('auditorium_hours')->default(0);
            $table->unsignedSmallInteger('lecture_hours')->default(0);
            $table->unsignedSmallInteger('exercise_hours')->default(0);
            $table->unsignedSmallInteger('self_study_hours')->default(0);
            $table->string('control_type', 30)->nullable();
        });

        foreach (DB::table('subjects')->orderBy('id')->get() as $subject) {
            $planRows = DB::table('curriculum_subjects as curriculum_subject')
                ->join('curriculum_versions as curriculum_version', 'curriculum_version.id', '=', 'curriculum_subject.curriculum_version_id')
                ->where('curriculum_subject.subject_id', $subject->id)
                ->orderByDesc('curriculum_version.is_active')
                ->orderByDesc('curriculum_version.version_year')
                ->orderByDesc('curriculum_subject.updated_at')
                ->orderByDesc('curriculum_subject.id')
                ->get([
                    'curriculum_subject.*',
                    'curriculum_version.is_active as version_is_active',
                    'curriculum_version.version_year',
                ]);
            $preferredPlanRow = $planRows->first();

            if ($preferredPlanRow !== null) {
                DB::table('subjects')->where('id', $subject->id)->update([
                    'auditorium_hours' => $preferredPlanRow->auditorium_hours ?? $preferredPlanRow->contact_hours ?? 0,
                    'lecture_hours' => $preferredPlanRow->lecture_hours ?? 0,
                    'exercise_hours' => $preferredPlanRow->exercise_hours ?? 0,
                    'self_study_hours' => $preferredPlanRow->self_study_hours ?? 0,
                    'control_type' => $preferredPlanRow->control_type,
                ]);
            }

            foreach ($planRows as $planRow) {
                $sourceData = json_decode((string) $planRow->source_data, true);
                $sourceData = is_array($sourceData) ? $sourceData : [];
                $sourceData['legacy_plan_values'] = [
                    'contact_hours' => $planRow->contact_hours,
                    'auditorium_hours' => $planRow->auditorium_hours,
                    'lecture_hours' => $planRow->lecture_hours,
                    'exercise_hours' => $planRow->exercise_hours,
                    'lecture_scheduled_hours' => $planRow->lecture_scheduled_hours,
                    'exercise_scheduled_hours' => $planRow->exercise_scheduled_hours,
                    'self_study_hours' => $planRow->self_study_hours,
                    'scheduled_hours' => $planRow->scheduled_hours,
                    'cycle_weeks' => $planRow->cycle_weeks,
                    'control_type' => $planRow->control_type,
                ];
                DB::table('curriculum_subjects')->where('id', $planRow->id)->update([
                    'source_data' => json_encode($sourceData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->dropColumn([
                'contact_hours',
                'auditorium_hours',
                'lecture_hours',
                'exercise_hours',
                'lecture_scheduled_hours',
                'exercise_scheduled_hours',
                'self_study_hours',
                'scheduled_hours',
                'cycle_weeks',
                'control_type',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->integer('contact_hours')->default(0);
            $table->unsignedSmallInteger('auditorium_hours')->nullable();
            $table->unsignedSmallInteger('lecture_hours')->nullable();
            $table->unsignedSmallInteger('exercise_hours')->nullable();
            $table->unsignedSmallInteger('lecture_scheduled_hours')->nullable();
            $table->unsignedSmallInteger('exercise_scheduled_hours')->nullable();
            $table->integer('self_study_hours')->default(0);
            $table->integer('scheduled_hours')->default(0);
            $table->json('cycle_weeks')->nullable();
            $table->string('control_type')->nullable();
        });

        foreach (DB::table('curriculum_subjects')->get() as $planRow) {
            $sourceData = json_decode((string) $planRow->source_data, true);
            $legacyValues = is_array($sourceData)
                ? ($sourceData['legacy_plan_values'] ?? [])
                : [];
            $subject = DB::table('subjects')->where('id', $planRow->subject_id)->first();

            if ($subject === null) {
                continue;
            }

            $values = [
                'contact_hours' => $legacyValues['contact_hours'] ?? $subject->auditorium_hours,
                'auditorium_hours' => $legacyValues['auditorium_hours'] ?? $subject->auditorium_hours,
                'lecture_hours' => $legacyValues['lecture_hours'] ?? $subject->lecture_hours,
                'exercise_hours' => $legacyValues['exercise_hours'] ?? $subject->exercise_hours,
                'lecture_scheduled_hours' => $legacyValues['lecture_scheduled_hours'] ?? null,
                'exercise_scheduled_hours' => $legacyValues['exercise_scheduled_hours'] ?? null,
                'self_study_hours' => $legacyValues['self_study_hours'] ?? $subject->self_study_hours,
                'scheduled_hours' => $legacyValues['scheduled_hours'] ?? 0,
                'cycle_weeks' => $legacyValues['cycle_weeks'] ?? null,
                'control_type' => $legacyValues['control_type'] ?? $subject->control_type,
            ];
            $values['cycle_weeks'] = is_array($values['cycle_weeks'])
                ? json_encode($values['cycle_weeks'], JSON_THROW_ON_ERROR)
                : $values['cycle_weeks'];

            DB::table('curriculum_subjects')->where('id', $planRow->id)->update($values);
        }

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn([
                'auditorium_hours',
                'lecture_hours',
                'exercise_hours',
                'self_study_hours',
                'control_type',
            ]);
        });

        Schema::dropIfExists('software_settings');
    }
};
