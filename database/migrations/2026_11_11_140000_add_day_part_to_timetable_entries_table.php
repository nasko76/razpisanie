<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetable_entries', function (Blueprint $table): void {
            $table->string('day_part', 20)->nullable()->after('weekday_no');
        });

        DB::table('timetable_entries')->update([
            'day_part' => DB::raw("CASE WHEN start_time < '12:00:00' THEN 'morning' WHEN start_time < '16:00:00' THEN 'afternoon' ELSE 'evening' END"),
        ]);
    }

    public function down(): void
    {
        Schema::table('timetable_entries', function (Blueprint $table): void {
            $table->dropColumn('day_part');
        });
    }
};
