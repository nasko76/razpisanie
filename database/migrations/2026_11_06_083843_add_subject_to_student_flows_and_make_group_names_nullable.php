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
        Schema::table('student_groups', function (Blueprint $table): void {
            $table->string('group_name')->nullable()->change();
        });

        Schema::table('student_flows', function (Blueprint $table): void {
            $table->foreignId('subject_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
        });

        $flowSubjectRecords = DB::table('teacher_assignments')
            ->whereNotNull('student_flow_id')
            ->whereNotNull('subject_id')
            ->select('student_flow_id', 'subject_id')
            ->unionAll(
                DB::table('timetable_entries')
                    ->whereNotNull('student_flow_id')
                    ->whereNotNull('subject_id')
                    ->select('student_flow_id', 'subject_id'),
            );
        $flowSubjects = DB::query()
            ->fromSub($flowSubjectRecords, 'flow_subject_records')
            ->select('student_flow_id')
            ->selectRaw('MIN(subject_id) as subject_id')
            ->groupBy('student_flow_id')
            ->havingRaw('COUNT(DISTINCT subject_id) = 1')
            ->get();

        foreach ($flowSubjects as $flowSubject) {
            DB::table('student_flows')
                ->where('id', $flowSubject->student_flow_id)
                ->whereNull('subject_id')
                ->update(['subject_id' => $flowSubject->subject_id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('student_groups')
            ->whereNull('group_name')
            ->orderBy('id')
            ->get(['id'])
            ->each(fn (object $group) => DB::table('student_groups')
                ->where('id', $group->id)
                ->update(['group_name' => 'legacy-group-'.$group->id]));

        Schema::table('student_groups', function (Blueprint $table): void {
            $table->string('group_name')->nullable(false)->change();
        });

        Schema::table('student_flows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('subject_id');
        });
    }
};
