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
        Schema::connection($this->connection)->create('company_database_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->string('driver', 20)->default('pgsql');
            $table->string('host', 255);
            $table->integer('port')->default(5432);
            $table->string('database', 100);
            $table->string('schema', 63)->default('public');

            // Daily ERP runtime credentials (least privileged)
            $table->string('app_username', 100);
            $table->text('app_password');

            // Migration / DDL credentials (schema maintenance)
            $table->string('migration_username', 100)->nullable();
            $table->text('migration_password')->nullable();

            // Initial provisioner credentials (database creation)
            $table->string('provisioner_username', 100)->nullable();
            $table->text('provisioner_password')->nullable();

            $table->string('ssl_mode', 20)->default('prefer');
            $table->boolean('is_external_host')->default(false);
            $table->timestamps();

            $table->unique(['host', 'port', 'database', 'schema'], 'company_db_conn_target_unique');
        });

        Schema::connection($this->connection)->create('provisioning_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('stage', 50);
            $table->string('status', 30);
            $table->jsonb('details')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('provisioning_logs');
        Schema::connection($this->connection)->dropIfExists('company_database_connections');
    }
};
