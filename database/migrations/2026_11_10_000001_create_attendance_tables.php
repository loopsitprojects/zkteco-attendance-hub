<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('viewer')->after('email');
        });

        // One row per person enrolled on the terminal, past or present.
        Schema::create('employees', function (Blueprint $table) {
            $table->string('user_id', 24)->primary();
            $table->unsignedInteger('device_uid')->nullable();
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('role')->default(0);
            $table->string('card', 32)->nullable();
            $table->string('group_name', 16)->nullable();
            $table->boolean('on_device')->default(true);
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 24);
            $table->string('employee_name')->nullable();
            $table->string('punch_state', 16);
            $table->string('verify_type', 20)->nullable();
            $table->unsignedSmallInteger('raw_status')->nullable();
            $table->unsignedSmallInteger('raw_punch')->nullable();
            $table->dateTime('punch_time');
            $table->string('source', 10)->default('device');
            $table->dateTime('created_at')->nullable();

            $table->unique(['user_id', 'punch_time']);
            $table->index('punch_time');
            $table->index(['source', 'created_at']);
        });

        // Values last reported by the terminal (model, serial, storage sizes, heartbeat...).
        Schema::create('device_state', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // Connection settings, shared by the website, the listener and scheduled sync.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('device_state');
        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('employees');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
