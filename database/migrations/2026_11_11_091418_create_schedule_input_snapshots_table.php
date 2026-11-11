<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedule_input_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('student_groups')->cascadeOnDelete();
            $table->string('academic_year', 20);
            $table->enum('semester_type', ['winter', 'summer']);
            $table->char('curriculum_fingerprint', 64);
            $table->char('assignment_fingerprint', 64);
            $table->timestamps();
            $table->unique(
                ['group_id', 'academic_year', 'semester_type'],
                'schedule_input_snapshots_scope_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_input_snapshots');
    }
};
