<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('name');
            $table->enum('type', ['lecture', 'exercise', 'atelier', 'practice', 'selfstudy', 'stream_lecture'])->default('lecture');
            $table->integer('default_hours')->default(0);
            $table->timestamps();
        });

        Schema::create('curriculum_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->integer('semester_no');
            $table->integer('course_year');
            $table->integer('contact_hours')->default(0);
            $table->integer('self_study_hours')->default(0);
            $table->integer('scheduled_hours')->default(0);
            $table->string('control_type')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_subjects');
        Schema::dropIfExists('subjects');
    }
};