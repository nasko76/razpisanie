<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('teaching_hours');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $periods = [
            ['name' => 'Morning', 'start_time' => '08:00:00', 'end_time' => '11:45:00', 'teaching_hours' => 4, 'sort_order' => 10],
            ['name' => 'Morning short', 'start_time' => '09:00:00', 'end_time' => '11:45:00', 'teaching_hours' => 3, 'sort_order' => 20],
            ['name' => 'Morning extended', 'start_time' => '08:00:00', 'end_time' => '12:45:00', 'teaching_hours' => 4, 'sort_order' => 30],
            ['name' => 'Afternoon short', 'start_time' => '13:00:00', 'end_time' => '15:45:00', 'teaching_hours' => 3, 'sort_order' => 40],
            ['name' => 'Afternoon long', 'start_time' => '12:00:00', 'end_time' => '15:45:00', 'teaching_hours' => 4, 'sort_order' => 50],
            ['name' => 'Afternoon extended', 'start_time' => '13:00:00', 'end_time' => '17:45:00', 'teaching_hours' => 4, 'sort_order' => 60],
            ['name' => 'Evening', 'start_time' => '16:00:00', 'end_time' => '18:30:00', 'teaching_hours' => 3, 'sort_order' => 70],
        ];
        DB::table('schedule_periods')->insert(array_map(
            fn (array $period): array => [...$period, 'created_at' => $now, 'updated_at' => $now],
            $periods,
        ));

        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->unsignedSmallInteger('auditorium_hours')->nullable();
            $table->unsignedSmallInteger('lecture_hours')->nullable();
            $table->unsignedSmallInteger('exercise_hours')->nullable();
        });
        DB::table('curriculum_subjects')
            ->whereNotNull('contact_hours')
            ->update(['auditorium_hours' => DB::raw('contact_hours')]);

        Schema::create('teacher_period_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('cycle_week');
            $table->unsignedTinyInteger('weekday_no');
            $table->foreignId('schedule_period_id')->constrained()->cascadeOnDelete();
            $table->enum('preference', ['available', 'unavailable']);
            $table->timestamps();
            $table->unique(['teacher_id', 'cycle_week', 'weekday_no', 'schedule_period_id'], 'teacher_period_preference_unique');
        });

        Schema::create('teacher_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('schedule_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('student_groups')->cascadeOnDelete();
            $table->string('academic_year', 20);
            $table->enum('semester_type', ['winter', 'summer']);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'academic_year', 'semester_type'], 'schedule_note_scope_unique');
        });

        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->foreignId('schedule_period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('custom_subject_name')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('subject_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('timetable_entries')->whereNull('subject_id')->exists()) {
            throw new RuntimeException('Remove free-form timetable entries before rolling back this migration.');
        }

        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_period_id');
            $table->dropColumn(['custom_subject_name', 'note']);
            $table->foreignId('subject_id')->nullable(false)->change();
        });

        Schema::dropIfExists('schedule_notes');
        Schema::dropIfExists('teacher_absences');
        Schema::dropIfExists('teacher_period_preferences');

        Schema::table('curriculum_subjects', function (Blueprint $table) {
            $table->dropColumn(['auditorium_hours', 'lecture_hours', 'exercise_hours']);
        });

        Schema::dropIfExists('schedule_periods');
    }
};
