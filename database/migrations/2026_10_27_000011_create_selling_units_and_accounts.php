<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Selling units with conversions, dated and unit-aware price lists,
 * contract prices, org ship-to locations, contacts in several orgs with a
 * role, and quotes with revisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('weight_kg', 12, 3)->nullable()->after('unit'); // per base unit
            $table->string('supplier_name')->nullable()->after('cost_cents');
            $table->unsignedBigInteger('previous_cost_cents')->nullable()->after('supplier_name');
            $table->timestamp('cost_changed_at')->nullable()->after('previous_cost_cents');
            $table->boolean('taxable')->default(true)->after('tax_code');
        });

        // One product, many ways to sell it. Stock is always kept in the
        // base unit (factor 1); every sale converts back to it.
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('code', 30)->nullable(); // a Unit enum value when it is one
            $table->decimal('factor', 16, 6)->default(1); // base units in one of this unit
            $table->boolean('is_base')->default(false);
            $table->unsignedBigInteger('price_cents')->default(0); // retail
            $table->string('variant_title', 100)->nullable(); // Shopify variant
            $table->string('measured_by', 30)->nullable(); // scale | bucket | count | tape
            $table->decimal('increment', 12, 3)->nullable(); // sold in steps of
            $table->decimal('min_quantity', 12, 3)->nullable();
            $table->unsignedBigInteger('deposit_cents')->default(0); // e.g. bulk bag deposit
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'name']);
            $table->index(['tenant_id', 'product_id']);
        });

        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropUnique(['price_list_id', 'product_id', 'min_quantity']);
            $table->foreignId('product_unit_id')->nullable()->after('product_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on')->nullable()->after('price_cents');
            $table->date('ends_on')->nullable()->after('starts_on');
            $table->index(['price_list_id', 'product_id', 'product_unit_id']);
        });

        Schema::create('contract_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price_cents');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id', 'product_id']);
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->decimal('unit_factor', 16, 6)->default(1)->after('unit');
            $table->decimal('base_quantity', 14, 3)->nullable()->after('unit_factor');
            $table->string('price_source', 20)->nullable()->after('price_overridden');
        });

        Schema::create('organization_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('region', 50)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->default('CA');
            $table->text('delivery_notes')->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete(); // default yard
            $table->boolean('po_required')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id']);
        });

        Schema::create('customer_organization', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('buyer'); // buyer | approver | admin
            $table->boolean('can_charge_account')->default(true);
            $table->timestamps();

            $table->unique(['customer_id', 'organization_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('organization_location_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->timestamp('quoted_at')->nullable()->after('confirmed_at');
            $table->date('quote_expires_on')->nullable()->after('quoted_at');
            $table->unsignedInteger('revision')->default(0)->after('quote_expires_on');
            $table->string('quote_token', 64)->nullable()->unique()->after('revision');
            $table->timestamp('quote_accepted_at')->nullable()->after('quote_token');
            $table->string('quote_accepted_by')->nullable()->after('quote_accepted_at');
        });

        Schema::create('order_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['order_id', 'revision']);
        });

        $this->backfill();
    }

    /**
     * Every product gets its base unit; every contact keeps their org.
     */
    private function backfill(): void
    {
        $now = now();

        foreach (DB::table('products')->get(['id', 'tenant_id', 'unit', 'price_cents']) as $product) {
            DB::table('product_units')->insert([
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'name' => self::label((string) $product->unit),
                'code' => $product->unit,
                'factor' => 1,
                'is_base' => true,
                'price_cents' => $product->price_cents,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (DB::table('customers')->whereNotNull('organization_id')->get(['id', 'tenant_id', 'organization_id']) as $customer) {
            DB::table('customer_organization')->insert([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'organization_id' => $customer->organization_id,
                'role' => 'buyer',
                'can_charge_account' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('order_lines')->update(['base_quantity' => DB::raw('quantity')]);
    }

    private static function label(string $unit): string
    {
        return match ($unit) {
            'tonne' => 'Tonne',
            'cubic_yard' => 'Cubic yard',
            'sq_ft' => 'Square foot',
            'lin_ft' => 'Linear foot',
            'each' => 'Each',
            default => ucfirst(str_replace('_', ' ', $unit)),
        };
    }

    public function down(): void
    {
        Schema::dropIfExists('order_revisions');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_location_id');
            $table->dropUnique(['quote_token']);
            $table->dropColumn(['quoted_at', 'quote_expires_on', 'revision', 'quote_token', 'quote_accepted_at', 'quote_accepted_by']);
        });
        Schema::dropIfExists('customer_organization');
        Schema::dropIfExists('organization_locations');
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn(['unit_factor', 'base_quantity', 'price_source']);
        });
        Schema::dropIfExists('contract_prices');
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropIndex(['price_list_id', 'product_id', 'product_unit_id']);
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn(['starts_on', 'ends_on']);
            $table->unique(['price_list_id', 'product_id', 'min_quantity']);
        });
        Schema::dropIfExists('product_units');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['weight_kg', 'supplier_name', 'previous_cost_cents', 'cost_changed_at', 'taxable']);
        });
    }
};
