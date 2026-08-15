<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passenger_trips', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('route_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 32)->default('active');

            $table->foreignId('boarding_stop_id')->nullable()->constrained('bus_stops')->nullOnDelete();
            $table->foreignId('alighting_stop_id')->nullable()->constrained('bus_stops')->nullOnDelete();

            $table->timestamp('boarded_at');
            $table->timestamp('alighted_at')->nullable();

            $table->unsignedBigInteger('fare_amount')->default(0);
            $table->foreignId('fare_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('distance_meters')->nullable();
            $table->unsignedSmallInteger('stops_travelled')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // How sure the system is that the passenger really left the bus.
            $table->decimal('alighting_confidence', 4, 3)->nullable();
            $table->string('alighting_source', 32)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['trip_id', 'status']);
            $table->index(['user_id', 'boarded_at']);
            $table->index(['city_id', 'boarded_at']);
        });

        Schema::create('passenger_boardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passenger_trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_stop_id')->nullable()->constrained()->nullOnDelete();

            // Which QR token proved presence, kept for forensics.
            $table->foreignId('bus_qr_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_nonce', 64)->nullable();

            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('distance_to_bus_meters', 8, 2)->nullable();

            $table->string('device_fingerprint', 64)->nullable();
            $table->string('client_ip', 45)->nullable();

            $table->timestamp('boarded_at');
            $table->timestamps();

            $table->index(['trip_id', 'boarded_at']);
            $table->unique(['token_nonce', 'user_id'], 'boardings_nonce_user_unique');
        });

        Schema::create('passenger_alightings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passenger_trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_stop_id')->nullable()->constrained()->nullOnDelete();

            $table->string('source', 32);
            $table->decimal('confidence', 4, 3);
            // Every signal that contributed, so a disputed charge is auditable.
            $table->json('signals')->nullable();

            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('distance_to_bus_meters', 8, 2)->nullable();

            $table->timestamp('detected_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'detected_at']);
        });

        // Opt-in, time-boxed passenger location samples used only to close an
        // active ride. Rows are pruned aggressively; see PruneLocationData.
        Schema::create('passenger_location_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passenger_trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->decimal('distance_to_bus_meters', 8, 2)->nullable();
            $table->timestamp('recorded_at');

            $table->index(['passenger_trip_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passenger_location_pings');
        Schema::dropIfExists('passenger_alightings');
        Schema::dropIfExists('passenger_boardings');
        Schema::dropIfExists('passenger_trips');
    }
};
