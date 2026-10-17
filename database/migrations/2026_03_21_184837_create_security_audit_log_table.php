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
        Schema::create('security_audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 100)->index();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // No FK — preserve historical data on user deletion
            $table->ipAddress('ip_address')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('occurred_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_audit_log');
    }
};
