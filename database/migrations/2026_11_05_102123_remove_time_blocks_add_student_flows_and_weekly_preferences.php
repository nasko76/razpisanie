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
        Schema::create('student_flows', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255)->unique();
            $table->timestamps();
        });

        Schema::create('student_flow_group', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['student_flow_id', 'student_group_id']);
        });

        Schema::table('teacher_assignments', function (Blueprint $table): void {
            $table->foreignId('student_flow_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('timetable_entries', function (Blueprint $table): void {
            $table->foreignId('student_flow_id')->nullable()->constrained()->nullOnDelete();
            $table->dropConstrainedForeignId('schedule_period_id');
        });

        if (Schema::hasTable('teacher_period_preferences')) {
            Schema::drop('teacher_period_preferences');
        }

        if (Schema::hasTable('schedule_periods')) {
            Schema::drop('schedule_periods');
        }

        if (Schema::hasTable('teacher_day_preferences')) {
            Schema::table('teacher_day_preferences', function (Blueprint $table): void {
                $table->index('teacher_id', 'tdp_teacher_fk_idx');
            });

            Schema::table('teacher_day_preferences', function (Blueprint $table): void {
                $table->dropUnique('teacher_day_preferences_teacher_id_weekday_no_unique');
                $table->unsignedTinyInteger('cycle_week')->default(1);
            });

            $preferences = DB::table('teacher_day_preferences')->get();
            foreach ([2, 3] as $cycleWeek) {
                DB::table('teacher_day_preferences')->insert(
                    $preferences->map(fn (object $preference): array => [
                        'teacher_id' => $preference->teacher_id,
                        'weekday_no' => $preference->weekday_no,
                        'preference' => $preference->preference,
                        'cycle_week' => $cycleWeek,
                        'created_at' => $preference->created_at,
                        'updated_at' => $preference->updated_at,
                    ])->all(),
                );
            }

            Schema::table('teacher_day_preferences', function (Blueprint $table): void {
                $table->unique(['teacher_id', 'cycle_week', 'weekday_no'], 'tdp_teacher_week_day_unique');
            });

            Schema::table('teacher_day_preferences', function (Blueprint $table): void {
                $table->dropIndex('tdp_teacher_fk_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('teacher_day_preferences')) {
            DB::table('teacher_day_preferences')->where('cycle_week', '!=', 1)->delete();
            Schema::table('teacher_day_preferences', function (Blueprint $table): void {
                $table->unique(['teacher_id', 'weekday_no'], 'teacher_day_preferences_teacher_id_weekday_no_unique');
            });

            Schema::table('teacher_day_preferences', function (Blueprint $table): void {
                $table->dropUnique('tdp_teacher_week_day_unique');
                $table->dropColumn('cycle_week');
            });
        }

        Schema::table('timetable_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('student_flow_id');
            $table->foreignId('schedule_period_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('teacher_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('student_flow_id');
        });

        Schema::dropIfExists('student_flow_group');
        Schema::dropIfExists('student_flows');

        Schema::create('schedule_periods', function (Blueprint $table): void {
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

        Schema::create('teacher_period_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('cycle_week');
            $table->unsignedTinyInteger('weekday_no');
            $table->foreignId('schedule_period_id')->constrained()->cascadeOnDelete();
            $table->enum('preference', ['available', 'unavailable']);
            $table->timestamps();
            $table->unique(['teacher_id', 'cycle_week', 'weekday_no', 'schedule_period_id'], 'teacher_period_preference_unique');
        });

        if (Schema::hasTable('teacher_day_preferences')) {
            $preferenceRows = DB::table('teacher_day_preferences')
                ->whereIn('preference', ['preferred', 'unavailable'])
                ->get();
            $periodIds = DB::table('schedule_periods')->pluck('id');
            $rows = [];
            foreach ($preferenceRows as $preference) {
                foreach ($periodIds as $periodId) {
                    $rows[] = [
                        'teacher_id' => $preference->teacher_id,
                        'cycle_week' => 1,
                        'weekday_no' => $preference->weekday_no,
                        'schedule_period_id' => $periodId,
                        'preference' => $preference->preference === 'unavailable' ? 'unavailable' : 'available',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            if ($rows !== []) {
                DB::table('teacher_period_preferences')->insert($rows);
            }
        }
    }
};
