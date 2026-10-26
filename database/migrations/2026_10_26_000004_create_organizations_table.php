<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organizations are buying accounts: contractors, landscapers, builders.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('account_number', 30);
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('region', 50)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->default('CA');
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('credit_limit_cents')->default(0);
            $table->string('payment_terms')->default('due_on_receipt');
            $table->boolean('credit_hold')->default(false);
            $table->boolean('tax_exempt')->default(false);
            $table->string('tax_exempt_number')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'account_number']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'last_name', 'first_name']);
            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('organizations');
    }
};
