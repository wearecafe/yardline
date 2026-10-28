<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A yard's existing data loaded from a spreadsheet: products, price lists,
 * accounts, job sites, opening stock and opening balances. Each upload is
 * checked first (the preview); nothing changes until it's imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30); // products | price_lists | organizations | job_sites | stock | balances
            $table->string('filename');
            $table->string('path');
            $table->string('status', 20)->default('checked'); // checked | imported | discarded
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('errors')->nullable(); // the first few hundred, for the preview
            $table->json('preview')->nullable(); // the first rows, as they'll be imported
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
