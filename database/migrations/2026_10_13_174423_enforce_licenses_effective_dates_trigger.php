<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        DB::connection($this->connection)->unprepared("
            CREATE OR REPLACE FUNCTION sync_license_effective_ends_at()
            RETURNS trigger AS $$
            BEGIN
                -- If expires_at is NULL, license is indefinite/perpetual
                IF NEW.expires_at IS NULL THEN
                    NEW.effective_ends_at := NULL;
                ELSE
                    -- If effective_ends_at is NULL, compute automatically from grace_period_days
                    IF NEW.effective_ends_at IS NULL THEN
                        NEW.effective_ends_at := NEW.expires_at + (COALESCE(NEW.grace_period_days, 0) || ' days')::interval;
                    END IF;

                    -- Validate effective_ends_at is not earlier than expires_at
                    IF NEW.effective_ends_at < NEW.expires_at THEN
                        RAISE EXCEPTION 'effective_ends_at cannot be earlier than expires_at for license %', NEW.license_key;
                    END IF;
                END IF;

                -- Validate effective_ends_at is not earlier than starts_at
                IF NEW.effective_ends_at IS NOT NULL AND NEW.effective_ends_at < NEW.starts_at THEN
                    RAISE EXCEPTION 'effective_ends_at cannot be earlier than starts_at for license %', NEW.license_key;
                END IF;

                -- Validate expires_at is not earlier than starts_at
                IF NEW.expires_at IS NOT NULL AND NEW.expires_at < NEW.starts_at THEN
                    RAISE EXCEPTION 'expires_at cannot be earlier than starts_at for license %', NEW.license_key;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_sync_license_effective_ends_at ON licenses;
            CREATE TRIGGER trg_sync_license_effective_ends_at
            BEFORE INSERT OR UPDATE ON licenses
            FOR EACH ROW EXECUTE FUNCTION sync_license_effective_ends_at();
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection($this->connection)->unprepared('
            DROP TRIGGER IF EXISTS trg_sync_license_effective_ends_at ON licenses;
            DROP FUNCTION IF EXISTS sync_license_effective_ends_at();
        ');
    }
};
