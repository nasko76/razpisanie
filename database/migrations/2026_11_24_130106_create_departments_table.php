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
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->foreignId('department_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
        });

        DB::table('teachers')
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->orderBy('id')
            ->get(['id', 'department'])
            ->each(function (object $teacher): void {
                $name = preg_replace('/\s+/u', ' ', trim((string) $teacher->department)) ?? trim((string) $teacher->department);
                if ($name === '') {
                    return;
                }

                $departmentId = DB::table('departments')->where('name', $name)->value('id');
                if ($departmentId === null) {
                    $departmentId = DB::table('departments')->insertGetId([
                        'name' => $name,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('teachers')->where('id', $teacher->id)->update([
                    'department' => $name,
                    'department_id' => $departmentId,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('departments');
    }
};
