<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-entered orders were stored with source 'yardline', the app's old
 * name. They're now 'staff', matching how they're labelled everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')->where('source', 'yardline')->update(['source' => 'staff']);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('source')->default('staff')->change(); // staff | counter | portal
        });
    }

    public function down(): void
    {
        DB::table('orders')->where('source', 'staff')->update(['source' => 'yardline']);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('source')->default('yardline')->change();
        });
    }
};
