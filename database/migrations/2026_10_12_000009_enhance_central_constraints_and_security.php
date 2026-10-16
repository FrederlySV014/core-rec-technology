<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        // 1. Case-insensitive unique index for users email
        DB::connection($this->connection)->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users (LOWER(email));'
        );

        // 2. Enhance company_database_connections
        Schema::connection($this->connection)->table('company_database_connections', function (Blueprint $table) {
            // Ensure company_id has an explicit unique constraint
            $table->unique('company_id', 'company_db_conn_company_unique');

            // Drop target unique constraint that allowed same DB with different schema
            $table->dropUnique('company_db_conn_target_unique');

            // One physical database per company regardless of schema
            $table->unique(['host', 'port', 'database'], 'company_db_conn_database_unique');

            // Remove shared server provisioning credentials from tenant connection records
            $table->dropColumn(['provisioner_username', 'provisioner_password']);
        });

        // 3. Enhance licenses with exact timestamp coverage and PostgreSQL exclusion constraint
        Schema::connection($this->connection)->table('licenses', function (Blueprint $table) {
            $table->timestampTz('effective_ends_at')->nullable()->after('grace_period_days');
        });

        // Populate initial effective_ends_at for existing records
        DB::connection($this->connection)->statement("
            UPDATE licenses
            SET effective_ends_at = CASE
                WHEN expires_at IS NULL THEN NULL
                ELSE expires_at + (grace_period_days || ' days')::interval
            END
        ");

        // Enable btree_gist extension in PostgreSQL
        DB::connection($this->connection)->statement('CREATE EXTENSION IF NOT EXISTS btree_gist;');

        // Add exclusion constraint to physically prevent overlapping licenses at the database engine level
        DB::connection($this->connection)->statement("
            ALTER TABLE licenses ADD CONSTRAINT licenses_no_overlap_excl
            EXCLUDE USING gist (
                company_id WITH =,
                tstzrange(starts_at, COALESCE(effective_ends_at, 'infinity'::timestamptz), '[)') WITH &&
            )
            WHERE (administrative_status IN ('active', 'suspended'));
        ");

        // 4. PostgreSQL triggers to enforce permission scopes on direct assignments
        DB::connection($this->connection)->unprepared("
            CREATE OR REPLACE FUNCTION check_permission_scope_match()
            RETURNS trigger AS $$
            DECLARE
                v_scope text;
            BEGIN
                SELECT scope INTO v_scope FROM permissions WHERE id = NEW.permission_id;

                IF TG_TABLE_NAME = 'platform_role_permissions' AND v_scope <> 'platform' THEN
                    RAISE EXCEPTION 'Cannot assign permission % with scope % to platform role.', NEW.permission_id, v_scope;
                END IF;

                IF TG_TABLE_NAME IN ('company_role_permissions', 'role_template_permissions') AND v_scope <> 'company' THEN
                    RAISE EXCEPTION 'Cannot assign permission % with scope % to %.', NEW.permission_id, v_scope, TG_TABLE_NAME;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_check_platform_role_permissions_scope ON platform_role_permissions;
            CREATE TRIGGER trg_check_platform_role_permissions_scope
            BEFORE INSERT OR UPDATE ON platform_role_permissions
            FOR EACH ROW EXECUTE FUNCTION check_permission_scope_match();

            DROP TRIGGER IF EXISTS trg_check_company_role_permissions_scope ON company_role_permissions;
            CREATE TRIGGER trg_check_company_role_permissions_scope
            BEFORE INSERT OR UPDATE ON company_role_permissions
            FOR EACH ROW EXECUTE FUNCTION check_permission_scope_match();

            DROP TRIGGER IF EXISTS trg_check_role_template_permissions_scope ON role_template_permissions;
            CREATE TRIGGER trg_check_role_template_permissions_scope
            BEFORE INSERT OR UPDATE ON role_template_permissions
            FOR EACH ROW EXECUTE FUNCTION check_permission_scope_match();
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection($this->connection)->unprepared('
            DROP TRIGGER IF EXISTS trg_check_role_template_permissions_scope ON role_template_permissions;
            DROP TRIGGER IF EXISTS trg_check_company_role_permissions_scope ON company_role_permissions;
            DROP TRIGGER IF EXISTS trg_check_platform_role_permissions_scope ON platform_role_permissions;
            DROP FUNCTION IF EXISTS check_permission_scope_match();
        ');

        DB::connection($this->connection)->statement('
            ALTER TABLE licenses DROP CONSTRAINT IF EXISTS licenses_no_overlap_excl;
        ');

        Schema::connection($this->connection)->table('licenses', function (Blueprint $table) {
            $table->dropColumn('effective_ends_at');
        });

        Schema::connection($this->connection)->table('company_database_connections', function (Blueprint $table) {
            $table->string('provisioner_username', 100)->nullable();
            $table->text('provisioner_password')->nullable();
            $table->dropUnique('company_db_conn_database_unique');
            $table->unique(['host', 'port', 'database', 'schema'], 'company_db_conn_target_unique');
            $table->dropUnique('company_db_conn_company_unique');
        });

        DB::connection($this->connection)->statement('DROP INDEX IF EXISTS users_email_lower_unique;');
    }
};
