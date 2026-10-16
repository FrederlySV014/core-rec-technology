<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The database connection that should be used by the migration.
     */
    protected $connection = 'central';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name', 200);
            $table->string('trade_name', 200)->nullable();
            $table->string('country_code', 2);
            $table->string('tax_id_type', 10);
            $table->string('tax_id', 20);
            $table->string('slug', 100)->unique();
            $table->string('contact_email', 255);
            $table->string('contact_phone', 50)->nullable();
            $table->string('administrative_status', 30)->default('pending');
            $table->string('technical_provisioning_status', 30)->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['country_code', 'tax_id_type', 'tax_id'], 'companies_tax_identity_unique');
            $table->index(['administrative_status', 'technical_provisioning_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('companies');
    }
};
