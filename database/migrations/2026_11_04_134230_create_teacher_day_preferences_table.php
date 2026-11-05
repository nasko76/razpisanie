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
        Schema::create('teacher_day_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday_no');
            $table->string('preference', 20)->default('neutral');
            $table->timestamps();
            $table->unique(['teacher_id', 'weekday_no']);
        });

        $now = now();

        foreach (DB::table('teachers')->get(['id', 'preferred_weekdays', 'unavailable_weekdays']) as $teacher) {
            $legacyPreferredDays = array_map('intval', json_decode($teacher->preferred_weekdays ?? '[]', true) ?: []);
            $legacyUnavailableDays = array_map('intval', json_decode($teacher->unavailable_weekdays ?? '[]', true) ?: []);
            $periodPreferences = DB::table('teacher_period_preferences')
                ->where('teacher_id', $teacher->id)
                ->get(['weekday_no', 'preference'])
                ->groupBy('weekday_no');
            $rows = [];

            for ($weekday = 1; $weekday <= 7; $weekday++) {
                $legacyDayPreferences = $periodPreferences->get($weekday, collect())
                    ->pluck('preference');
                $preference = match (true) {
                    $legacyDayPreferences->contains('unavailable'),
                    in_array($weekday, $legacyUnavailableDays, true) => 'unavailable',
                    $legacyDayPreferences->contains('available'),
                    in_array($weekday, $legacyPreferredDays, true) => 'preferred',
                    $weekday >= 6 => 'unavailable',
                    default => 'neutral',
                };
                $rows[] = [
                    'teacher_id' => $teacher->id,
                    'weekday_no' => $weekday,
                    'preference' => $preference,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('teacher_day_preferences')->insert($rows);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_day_preferences');
    }
};
