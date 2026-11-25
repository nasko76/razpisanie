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
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 9);
            $table->string('semester_type', 10);
            $table->string('status', 10)->default('archived');
            $table->timestamps();
            $table->unique(['academic_year', 'semester_type']);
        });

        $now = now();
        $academicYearStart = $now->month >= 9 ? $now->year : $now->year - 1;
        $currentAcademicYear = $academicYearStart.'/'.($academicYearStart + 1);
        $currentSemesterType = $now->month >= 2 && $now->month < 9 ? 'summer' : 'winter';
        $planningAcademicYear = $currentSemesterType === 'winter'
            ? $currentAcademicYear
            : ($academicYearStart + 1).'/'.($academicYearStart + 2);
        $planningSemesterType = $currentSemesterType === 'winter' ? 'summer' : 'winter';
        $terms = collect([
            ...DB::table('timetable_entries')->select('academic_year', 'semester_type')->distinct()->get()->all(),
            ...DB::table('schedule_notes')->select('academic_year', 'semester_type')->distinct()->get()->all(),
        ])
            ->filter(fn (object $term): bool => is_string($term->academic_year) && preg_match('/^\d{4}\/\d{4}$/', $term->academic_year) === 1
                && in_array($term->semester_type, ['winter', 'summer'], true))
            ->mapWithKeys(fn (object $term): array => [$term->academic_year.'|'.$term->semester_type => [
                'academic_year' => $term->academic_year,
                'semester_type' => $term->semester_type,
                'status' => 'archived',
            ]]);
        $terms->put($currentAcademicYear.'|'.$currentSemesterType, [
            'academic_year' => $currentAcademicYear,
            'semester_type' => $currentSemesterType,
            'status' => 'current',
        ]);
        $terms->put($planningAcademicYear.'|'.$planningSemesterType, [
            'academic_year' => $planningAcademicYear,
            'semester_type' => $planningSemesterType,
            'status' => 'planning',
        ]);

        foreach ($terms as $term) {
            DB::table('academic_terms')->insert([
                ...$term,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_terms');
    }
};
