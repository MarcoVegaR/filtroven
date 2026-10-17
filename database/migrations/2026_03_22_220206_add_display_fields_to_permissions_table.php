<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('guard_name');
            $table->string('display_name', 100)->nullable()->after('is_active');
            $table->text('description')->nullable()->after('display_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            $table->dropColumn(['is_active', 'display_name', 'description']);
        });
    }
};
