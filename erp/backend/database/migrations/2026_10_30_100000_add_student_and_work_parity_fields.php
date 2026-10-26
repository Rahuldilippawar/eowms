<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('roll_no', 30)->nullable()->after('student_code');
            $table->string('admission_no', 50)->nullable()->after('roll_no');
            $table->unsignedTinyInteger('year_of_study')->nullable()->after('section');
            $table->string('academic_year', 9)->nullable()->after('year_of_study');
            $table->string('mobile', 30)->nullable()->after('guardian_phone');
            $table->string('email')->nullable()->after('mobile');

            $table->index(['organization_id', 'academic_year']);
        });

        Schema::table('work_entries', function (Blueprint $table) {
            // Who asked for the work (free text, e.g. "Principal's office").
            $table->string('requested_by')->nullable()->after('description');
            $table->decimal('estimated_cost', 12, 2)->nullable()->after('hours');
            $table->decimal('actual_cost', 12, 2)->nullable()->after('estimated_cost');
            $table->string('before_photo_path')->nullable()->after('actual_cost');
            $table->string('after_photo_path')->nullable()->after('before_photo_path');
            // Set on entries brought in from the old app's records.
            $table->boolean('is_imported')->default(false)->after('after_photo_path');
        });

        // Colleagues who did the work together with the employee who logged it.
        Schema::create('work_entry_team', function (Blueprint $table) {
            $table->foreignId('work_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->primary(['work_entry_id', 'employee_id']);
            $table->index('employee_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->decimal('cost_incurred', 12, 2)->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('cost_incurred'));
        Schema::dropIfExists('work_entry_team');
        Schema::table('work_entries', fn (Blueprint $table) => $table->dropColumn([
            'requested_by', 'estimated_cost', 'actual_cost', 'before_photo_path', 'after_photo_path', 'is_imported',
        ]));
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'academic_year']);
            $table->dropColumn(['roll_no', 'admission_no', 'year_of_study', 'academic_year', 'mobile', 'email']);
        });
    }
};
