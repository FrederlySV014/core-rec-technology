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
        Schema::connection($this->connection)->create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('license_key', 64)->unique();
            $table->string('plan_name', 100);
            $table->string('administrative_status', 30)->default('active');
            $table->timestampTz('starts_at');
            $table->timestampTz('expires_at')->nullable();
            $table->integer('grace_period_days')->default(0);
            $table->integer('max_users')->default(5);
            $table->integer('max_branches')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'administrative_status', 'starts_at', 'expires_at'], 'licenses_resolution_index');
        });

        Schema::connection($this->connection)->create('license_modules', function (Blueprint $table) {
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->jsonb('settings')->nullable();

            $table->primary(['license_id', 'module_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('license_modules');
        Schema::connection($this->connection)->dropIfExists('licenses');
    }
};
