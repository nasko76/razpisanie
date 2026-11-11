<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetable_entries', function (Blueprint $table): void {
            $table->boolean('is_in_pool')->default(false);
            $table->string('pool_reason', 20)->nullable();
            $table->decimal('auditorium_hours', 5, 2)->default(0);
            $table->decimal('self_study_hours', 5, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('timetable_entries', function (Blueprint $table): void {
            $table->dropColumn(['is_in_pool', 'pool_reason', 'auditorium_hours', 'self_study_hours']);
        });
    }
};
