<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table): void {
            $table->string('subject_category', 20)->default('mandatory');
            $table->boolean('is_selected')->default(true);
        });

        DB::table('curriculum_subjects')
            ->join('subjects', 'subjects.id', '=', 'curriculum_subjects.subject_id')
            ->select(['curriculum_subjects.id', 'subjects.name'])
            ->orderBy('curriculum_subjects.id')
            ->get()
            ->each(function (object $row): void {
                $name = mb_strtolower(trim($row->name));
                $category = match (true) {
                    str_starts_with($name, 'избираема дисциплина'),
                    str_starts_with($name, 'избираеми дисциплини') => 'elective',
                    str_starts_with($name, 'факултативна дисциплина'),
                    str_starts_with($name, 'факултативни дисциплини') => 'optional',
                    default => 'mandatory',
                };

                if ($category !== 'mandatory') {
                    DB::table('curriculum_subjects')
                        ->where('id', $row->id)
                        ->update([
                            'subject_category' => $category,
                            'is_selected' => false,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table): void {
            $table->dropColumn(['subject_category', 'is_selected']);
        });
    }
};
