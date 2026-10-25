<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // NULL = central (organization-level) department.
            $table->foreignId('institution_id')->nullable()->constrained()->restrictOnDelete();
            // MySQL treats NULLs as distinct in unique keys; this makes central
            // department codes unique per organization too.
            $table->unsignedBigInteger('institution_key')->storedAs('IFNULL(`institution_id`, 0)');
            $table->string('name');
            $table->string('code', 30);
            $table->string('category', 50)->nullable(); // IT, HR, Accounts, Maintenance, …
            $table->foreignId('head_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'institution_key', 'code']);
            $table->index(['organization_id', 'institution_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
