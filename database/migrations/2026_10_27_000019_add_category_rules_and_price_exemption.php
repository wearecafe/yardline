<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products that no price list, contract price or discount can touch, and
 * price list rules that take $ or % off a whole category.
 *
 * A price list can say whether its category rules beat its product prices
 * (null follows the tenant default in tenants.settings['pricing']), and
 * whether a category discount stacks on a volume-break price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('price_list_exempt')->default(false)->after('taxable');
        });

        Schema::table('price_lists', function (Blueprint $table) {
            $table->boolean('category_rules_override')->nullable()->after('description');
            $table->boolean('stack_volume_breaks')->default(false)->after('category_rules_override');
        });

        Schema::create('price_list_category_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('discount_type', 10); // percent | amount
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->unsignedInteger('discount_cents')->nullable(); // off each base unit
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->unique(['price_list_id', 'category']);
            $table->index(['tenant_id', 'category']);
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->foreignId('category_rule_id')->nullable()->after('price_source')
                ->constrained('price_list_category_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_rule_id');
        });

        Schema::dropIfExists('price_list_category_rules');

        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropColumn(['category_rules_override', 'stack_volume_breaks']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('price_list_exempt');
        });
    }
};
