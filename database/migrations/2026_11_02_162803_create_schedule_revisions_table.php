<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('student_groups')->cascadeOnDelete();
            $table->string('academic_year', 20);
            $table->enum('semester_type', ['winter', 'summer']);
            $table->unsignedInteger('revision_no');
            $table->string('reason');
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(
                ['group_id', 'academic_year', 'semester_type', 'revision_no'],
                'schedule_revisions_scope_revision_unique',
            );
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_revisions');
    }
};
