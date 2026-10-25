<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalogue of every action the API knows, e.g. "institutions.view".
        // Rows come from config/permissions.php (synced by a seeder/command).
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('module', 50)->index();
            $table->string('action', 30);
            $table->timestamps();
        });

        // Roles belong to one organization; each organization defines its own.
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false); // seeded defaults; cannot be deleted
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });

        // What a role may do, and over which records (see App\Enums\PermissionScope).
        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 20);
            $table->primary(['role_id', 'permission_id']);
        });

        // A user holds a role in an organization, optionally limited to some
        // institutions / departments (used by the "institutions" and
        // "departments" scopes).
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
            $table->index(['user_id', 'organization_id']);
        });

        Schema::create('role_assignment_institution', function (Blueprint $table) {
            $table->foreignId('role_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_assignment_id', 'institution_id'], 'rai_primary');
        });

        Schema::create('role_assignment_department', function (Blueprint $table) {
            $table->foreignId('role_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_assignment_id', 'department_id'], 'rad_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignment_department');
        Schema::dropIfExists('role_assignment_institution');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
