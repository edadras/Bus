<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['city_id', 'code']);
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Every driver is a user; drivers can never self-register, the
            // account is always created by an administrator.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();

            $table->string('employee_code', 32)->nullable();
            $table->string('national_code', 20);
            $table->string('license_number', 32);
            $table->string('license_class', 16)->nullable();
            $table->date('license_expires_at')->nullable();

            $table->string('status', 32)->default('pending_approval');

            $table->date('hired_at')->nullable();
            $table->date('contract_ends_at')->nullable();

            $table->unsignedInteger('total_trips')->default(0);
            $table->unsignedInteger('total_shift_minutes')->default(0);
            $table->decimal('rating', 3, 2)->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['city_id', 'national_code']);
            $table->index(['city_id', 'status']);
        });

        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'type']);
        });

        Schema::create('buses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();

            $table->string('bus_number', 32);
            $table->string('plate', 32)->nullable();
            $table->string('vin', 32)->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('manufacture_year')->nullable();

            $table->unsignedSmallInteger('capacity_seated')->default(30);
            $table->unsignedSmallInteger('capacity_standing')->default(20);

            $table->boolean('has_air_conditioning')->default(true);
            $table->boolean('is_accessible')->default(false);

            $table->string('status', 32)->default('idle');

            // Denormalised operational pointers, kept in sync by the trip
            // service so the live map does not need a join per bus.
            $table->foreignId('current_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            // Denormalised pointer to trips.id. Deliberately not a foreign
            // key: trips references buses, so a constraint here would make the
            // two tables mutually dependent and unloadable in one pass.
            $table->unsignedBigInteger('current_trip_id')->nullable()->index();
            $table->foreignId('default_line_id')->nullable()->constrained('bus_lines')->nullOnDelete();

            $table->timestamp('last_ping_at')->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();

            $table->date('inspection_due_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['city_id', 'bus_number']);
            $table->index(['city_id', 'status']);
            $table->index('last_ping_at');
        });

        Schema::create('bus_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->default('validator');
            $table->string('identifier', 64);
            $table->string('firmware_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'identifier']);
        });

        Schema::create('bus_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();

            // Public, printed identifier. Safe to expose.
            $table->string('public_id', 32)->unique();
            // HMAC key behind the rotating token. Encrypted at rest.
            $table->text('secret');
            $table->unsignedInteger('version')->default(1);

            $table->boolean('is_active')->default(true);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason')->nullable();

            $table->timestamps();

            $table->index(['bus_id', 'is_active']);
        });

        Schema::create('bus_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_line_id')->nullable()->constrained()->nullOnDelete();

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['driver_id', 'is_active']);
            $table->index(['bus_id', 'is_active']);
        });

        Schema::create('driver_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 32)->default('open');

            $table->unsignedInteger('trip_count')->default(0);
            $table->unsignedInteger('passenger_count')->default(0);
            $table->unsignedBigInteger('revenue_minor')->default(0);
            $table->unsignedInteger('distance_meters')->default(0);

            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 10, 7)->nullable();

            $table->timestamps();

            $table->index(['driver_id', 'status']);
            $table->index(['bus_id', 'status']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_shifts');
        Schema::dropIfExists('bus_assignments');
        Schema::dropIfExists('bus_qr_codes');
        Schema::dropIfExists('bus_devices');
        Schema::dropIfExists('buses');
        Schema::dropIfExists('driver_documents');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('operators');
    }
};
