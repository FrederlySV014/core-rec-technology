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
        Schema::connection($this->connection)->create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20); // 'platform', 'company'
            $table->foreignId('module_id')->nullable()->constrained('modules')->nullOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['scope', 'module_id']);
        });

        // 1. Platform IAM (Global / SaaS operators)
        Schema::connection($this->connection)->create('platform_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('platform_role_permissions', function (Blueprint $table) {
            $table->foreignId('platform_role_id')->constrained('platform_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['platform_role_id', 'permission_id']);
        });

        Schema::connection($this->connection)->create('platform_user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('platform_role_id')->constrained('platform_roles')->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();

            $table->primary(['user_id', 'platform_role_id']);
        });

        // 2. Role Templates (Global blueprints to clone into companies, never directly assigned to memberships)
        Schema::connection($this->connection)->create('role_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('role_template_permissions', function (Blueprint $table) {
            $table->foreignId('role_template_id')->constrained('role_templates')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['role_template_id', 'permission_id']);
        });

        // 3. Company-scoped Roles
        Schema::connection($this->connection)->create('company_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 50);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'slug'], 'company_roles_company_slug_unique');
            $table->unique(['company_id', 'id'], 'company_roles_company_id_unique');
        });

        Schema::connection($this->connection)->create('company_role_permissions', function (Blueprint $table) {
            $table->foreignId('company_role_id')->constrained('company_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['company_role_id', 'permission_id']);
        });

        // 4. Membership Role Assignments with strict composite foreign keys
        Schema::connection($this->connection)->create('membership_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('company_role_id');
            $table->timestamps();

            $table->unique(['membership_id', 'company_role_id'], 'membership_roles_member_role_unique');

            $table->foreign(['company_id', 'membership_id'])
                ->references(['company_id', 'id'])
                ->on('memberships')
                ->cascadeOnDelete();

            $table->foreign(['company_id', 'company_role_id'])
                ->references(['company_id', 'id'])
                ->on('company_roles')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('membership_roles');
        Schema::connection($this->connection)->dropIfExists('company_role_permissions');
        Schema::connection($this->connection)->dropIfExists('company_roles');
        Schema::connection($this->connection)->dropIfExists('role_template_permissions');
        Schema::connection($this->connection)->dropIfExists('role_templates');
        Schema::connection($this->connection)->dropIfExists('platform_user_roles');
        Schema::connection($this->connection)->dropIfExists('platform_role_permissions');
        Schema::connection($this->connection)->dropIfExists('platform_roles');
        Schema::connection($this->connection)->dropIfExists('permissions');
    }
};
