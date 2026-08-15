<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();

            // Mobile is the primary credential for passengers and drivers; it
            // is stored normalised to E.164 without the leading plus.
            $table->string('mobile', 20)->unique();
            $table->timestamp('mobile_verified_at')->nullable();

            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();

            // Nullable: OTP-only passengers never set a password. Staff do.
            $table->string('password')->nullable();

            $table->string('national_code', 20)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('locale', 8)->default('fa');
            $table->string('timezone', 64)->default('Asia/Tehran');

            $table->string('status', 32)->default('active');

            // Home city; the platform is multi-city from day one.
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->json('preferences')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'city_id']);
            $table->index('created_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
