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
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->dropForeign(['curriculum_version_id']);
            $table->unsignedBigInteger('curriculum_version_id')->nullable()->change();
            $table->foreign('curriculum_version_id')
                ->references('id')
                ->on('curriculum_versions')
                ->nullOnDelete();
            $table->foreignId('student_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('academic_year', 9)->nullable();
            $table->unsignedSmallInteger('planned_hours')->nullable();
            $table->string('employment_category', 20)->nullable();
            $table->index(
                ['academic_year', 'employment_category', 'student_group_id'],
                'teacher_assignment_import_scope_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('teacher_assignments', 'curriculum_version_id')
            && DB::table('teacher_assignments')->whereNull('curriculum_version_id')->exists()) {
            throw new RuntimeException('Imported group-scoped assignments need a curriculum version before this migration can be rolled back.');
        }

        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->dropIndex('teacher_assignment_import_scope_idx');
            $table->dropConstrainedForeignId('student_group_id');
            $table->dropForeign(['curriculum_version_id']);
            $table->unsignedBigInteger('curriculum_version_id')->nullable(false)->change();
            $table->foreign('curriculum_version_id')
                ->references('id')
                ->on('curriculum_versions')
                ->cascadeOnDelete();
            $table->dropColumn(['academic_year', 'planned_hours', 'employment_category']);
        });
    }
};
