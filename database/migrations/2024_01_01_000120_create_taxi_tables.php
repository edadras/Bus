<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taxis.
 *
 * A taxi is a second kind of vehicle on the same network, sharing the city, the
 * driver record, the wallet and the ledger with the bus fleet — but it earns in
 * three quite different ways, and the schema keeps those three honest rather
 * than collapsing them into one nullable-everything table:
 *
 *   line    a fixed route with a published flat fare
 *   charter the driver names a price for one hire
 *   meter   distance and waiting time, priced from the city's tariff
 *
 * Which of the three a taxi is offering right now is a property of the open
 * shift, not of the vehicle: the same car runs a line in the morning and takes
 * charters in the evening. What the vehicle carries is which modes it is
 * *permitted* to run at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxi_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name');
            $table->string('origin_label')->nullable();
            $table->string('destination_label')->nullable();
            $table->string('color', 9)->nullable();

            // The published flat fare for the whole line. Held here rather than
            // in the tariff table because a line's fare is the line's own
            // property — two lines in one city routinely differ.
            $table->unsignedBigInteger('flat_fare');

            $table->decimal('origin_lat', 10, 7)->nullable();
            $table->decimal('origin_lng', 10, 7)->nullable();
            $table->decimal('destination_lat', 10, 7)->nullable();
            $table->decimal('destination_lng', 10, 7)->nullable();

            $table->unsignedSmallInteger('typical_duration_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('provenance', 32)->default('official');

            $table->timestamps();

            $table->unique(['city_id', 'code']);
            $table->index(['city_id', 'is_active']);
        });

        Schema::create('taxis', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();

            // Body number painted on the car, unique within the city.
            $table->string('taxi_number', 32);
            $table->string('plate', 32)->nullable();
            $table->string('model')->nullable();
            $table->string('color', 32)->nullable();
            $table->unsignedSmallInteger('manufacture_year')->nullable();
            $table->unsignedTinyInteger('capacity')->default(4);

            $table->boolean('has_air_conditioning')->default(true);
            $table->boolean('is_accessible')->default(false);

            $table->string('status', 32)->default('idle');

            // Which of the three modes this car is licensed to run. A line-only
            // taxi must never be able to open a metered shift.
            $table->json('allowed_modes')->nullable();
            $table->foreignId('default_taxi_line_id')->nullable()->constrained('taxi_lines')->nullOnDelete();

            // Commission withheld from each fare, in basis points. Null falls
            // back to the city-wide default, so the common case needs no row.
            $table->unsignedSmallInteger('commission_bps')->nullable();

            $table->foreignId('current_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            // Denormalised pointer to taxi_shifts.id; not a foreign key because
            // taxi_shifts references taxis and the pair would be unloadable.
            $table->unsignedBigInteger('current_shift_id')->nullable()->index();

            $table->timestamp('last_ping_at')->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();

            $table->date('inspection_due_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['city_id', 'taxi_number']);
            $table->index(['city_id', 'status']);
        });

        Schema::create('taxi_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxi_id')->constrained()->cascadeOnDelete();

            $table->string('public_id', 32)->unique();
            $table->text('secret');
            $table->unsignedInteger('version')->default(1);

            $table->boolean('is_active')->default(true);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason')->nullable();

            $table->timestamps();

            $table->index(['taxi_id', 'is_active']);
        });

        Schema::create('taxi_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxi_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['driver_id', 'is_active']);
            $table->index(['taxi_id', 'is_active']);
        });

        Schema::create('taxi_tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('service_type', 32);

            // The metered figures. All in minor units; a null per-minute rate
            // means waiting is not charged at all in this city.
            $table->unsignedBigInteger('base_fare')->default(0);
            $table->unsignedBigInteger('per_km_fare')->default(0);
            $table->unsignedBigInteger('per_minute_waiting_fare')->default(0);
            $table->unsignedBigInteger('minimum_fare')->default(0);
            $table->unsignedBigInteger('maximum_fare')->nullable();

            // Below this speed the car counts as waiting rather than running,
            // so a queue at a light is not billed as distance it never covered.
            $table->unsignedSmallInteger('waiting_speed_kmh')->default(5);

            // Night and peak surcharges, applied as a multiplier.
            $table->decimal('multiplier', 5, 2)->default(1);
            $table->time('valid_from_time')->nullable();
            $table->time('valid_to_time')->nullable();

            $table->unsignedSmallInteger('priority')->default(10);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['city_id', 'service_type', 'is_active']);
        });

        Schema::create('taxi_shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('taxi_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            // The mode being offered right now. This is what colours the car on
            // the map and what decides how the next scan is priced.
            $table->string('service_type', 32);
            $table->foreignId('taxi_line_id')->nullable()->constrained('taxi_lines')->nullOnDelete();

            // The price the driver has named for the hire in front of them.
            // Cleared as soon as it is taken, so it can never be charged twice.
            $table->unsignedBigInteger('pending_charter_amount')->nullable();
            $table->timestamp('pending_charter_set_at')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 32)->default('open');

            $table->unsignedInteger('ride_count')->default(0);
            $table->unsignedInteger('boarding_count')->default(0);
            $table->unsignedInteger('alighting_count')->default(0);
            $table->unsignedInteger('onboard_count')->default(0);
            $table->unsignedBigInteger('gross_minor')->default(0);
            $table->unsignedBigInteger('commission_minor')->default(0);
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->unsignedInteger('distance_meters')->default(0);

            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 10, 7)->nullable();

            $table->timestamps();

            $table->index(['driver_id', 'status']);
            $table->index(['taxi_id', 'status']);
            $table->index(['city_id', 'status']);
            $table->index('started_at');
        });

        Schema::create('taxi_rides', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taxi_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taxi_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taxi_line_id')->nullable()->constrained('taxi_lines')->nullOnDelete();
            $table->foreignId('taxi_tariff_id')->nullable()->constrained('taxi_tariffs')->nullOnDelete();

            $table->string('service_type', 32);
            $table->string('status', 32)->default('active');

            // What was actually charged, and what the platform kept. Both are
            // written from the posting, never computed for display.
            $table->unsignedBigInteger('fare_amount')->default(0);
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->unsignedBigInteger('quoted_amount')->nullable();
            $table->json('fare_breakdown')->nullable();

            // A metered ride can end with the wallet short. The debt is
            // recorded rather than written off, and it blocks the next ride.
            $table->unsignedBigInteger('outstanding_amount')->default(0);

            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();

            $table->unsignedInteger('distance_meters')->default(0);
            $table->unsignedInteger('waiting_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);

            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 10, 7)->nullable();

            $table->string('qr_public_id', 32)->nullable();
            $table->string('token_nonce', 64)->nullable();
            $table->string('device_fingerprint', 128)->nullable();
            $table->string('client_ip', 45)->nullable();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_by', 32)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['taxi_shift_id', 'status']);
            $table->index(['city_id', 'created_at']);
            $table->index(['driver_id', 'created_at']);
        });

        Schema::create('taxi_meter_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxi_ride_id')->constrained()->cascadeOnDelete();

            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('speed_kmh', 6, 2)->nullable();
            $table->decimal('accuracy', 6, 2)->nullable();

            // The increment this sample contributed. Storing the deltas rather
            // than only the total is what makes a disputed fare answerable.
            $table->unsignedInteger('distance_delta_meters')->default(0);
            $table->unsignedInteger('elapsed_seconds')->default(0);
            $table->boolean('is_waiting')->default(false);
            $table->boolean('is_discarded')->default(false);
            $table->string('discard_reason', 32)->nullable();

            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['taxi_ride_id', 'recorded_at']);
        });

        Schema::create('taxi_settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40)->unique();
            $table->string('status', 32)->default('requested');

            $table->date('period_start');
            $table->date('period_end');

            $table->unsignedBigInteger('gross_amount')->default(0);
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->unsignedInteger('ride_count')->default(0);

            $table->string('iban', 34)->nullable();
            $table->string('bank_account_holder')->nullable();
            $table->string('payment_reference', 120)->nullable();

            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['driver_id', 'status']);
            $table->index(['city_id', 'status']);
        });

        // Which settlement a ride was paid out in. Kept on the ride so a ride
        // can be claimed exactly once, the same guarantee the merchant payout
        // relies on to be safe to re-run.
        Schema::table('taxi_rides', function (Blueprint $table) {
            $table->foreignId('taxi_settlement_id')->nullable()->after('wallet_transaction_id')
                ->constrained('taxi_settlements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('taxi_rides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('taxi_settlement_id');
        });

        Schema::dropIfExists('taxi_settlements');
        Schema::dropIfExists('taxi_meter_samples');
        Schema::dropIfExists('taxi_rides');
        Schema::dropIfExists('taxi_shifts');
        Schema::dropIfExists('taxi_tariffs');
        Schema::dropIfExists('taxi_assignments');
        Schema::dropIfExists('taxi_qr_codes');
        Schema::dropIfExists('taxis');
        Schema::dropIfExists('taxi_lines');
    }
};
