<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the commercial tenant migrations.
     */
    public function up(): void
    {
        Schema::create('tenant_identity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->string('tax_id', 30);
            $table->string('schema_version', 20)->default('1.0.0');
            $table->timestampTz('provisioned_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_identity');
    }
};
