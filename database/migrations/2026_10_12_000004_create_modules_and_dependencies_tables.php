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
        Schema::connection($this->connection)->create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_core')->default(false);
            $table->string('status', 30)->default('active');
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('module_dependencies', function (Blueprint $table) {
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->foreignId('depends_on_module_id')->constrained('modules')->cascadeOnDelete();

            $table->primary(['module_id', 'depends_on_module_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('module_dependencies');
        Schema::connection($this->connection)->dropIfExists('modules');
    }
};
