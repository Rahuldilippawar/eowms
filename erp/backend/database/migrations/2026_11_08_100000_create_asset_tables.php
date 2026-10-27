<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The organization's list of asset items that departments register (the old dead stock item master).
        Schema::create('asset_item_masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('item_name');
            $table->string('category', 100)->nullable();
            $table->string('default_unit', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'item_name']);
        });

        // A department's dead stock register: furniture, equipment and other lasting assets.
        Schema::create('dead_stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_master_id')->constrained('asset_item_masters')->restrictOnDelete();
            // DS-{department code}-0001
            $table->string('item_code', 40);
            $table->string('item_name');
            $table->string('category', 100)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('unit', 30)->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 14, 2)->nullable();
            // A new purchase is charged to the department budget; an existing asset is only recorded.
            $table->boolean('is_new_purchase')->default(false);
            // good | repairable | bad | scrap
            $table->string('condition', 12)->default('good');
            $table->string('location')->nullable();
            $table->string('remarks', 1000)->nullable();
            $table->foreignId('added_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'item_code']);
            $table->index(['department_id', 'condition']);
        });

        // Things given to staff: permanent allocations (a laptop) or loans with a due time (a key).
        Schema::create('allocation_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_loan')->default(false);
            // Extra fields asked when giving an item of this type: [{key, label, type, options, required}]
            $table->json('fields')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('allocation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('type_id')->constrained('allocation_types')->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('default_location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type_id', 'name']);
        });

        Schema::create('asset_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('type_id')->constrained('allocation_types')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('allocation_items')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->json('custom')->nullable();
            $table->string('purpose')->nullable();
            $table->timestamp('allocated_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('return_reason', 500)->nullable();
            // A transfer closes one allocation and opens the next.
            $table->foreignId('transferred_from_id')->nullable()->constrained('asset_allocations')->nullOnDelete();
            $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['item_id', 'returned_at']);
            $table->index(['organization_id', 'user_id', 'returned_at']);
        });
    }

    public function down(): void
    {
        foreach (['asset_allocations', 'allocation_items', 'allocation_types', 'dead_stock_items', 'asset_item_masters'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
