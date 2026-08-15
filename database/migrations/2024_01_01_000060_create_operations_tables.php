<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bus_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();

            $table->string('status', 32)->default('scheduled');

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->foreignId('origin_stop_id')->nullable()->constrained('bus_stops')->nullOnDelete();
            $table->foreignId('destination_stop_id')->nullable()->constrained('bus_stops')->nullOnDelete();

            // Live snapshot. MySQL is the durable copy; Redis holds the hot one.
            $table->decimal('current_lat', 10, 7)->nullable();
            $table->decimal('current_lng', 10, 7)->nullable();
            $table->decimal('current_speed_kmh', 6, 2)->nullable();
            $table->decimal('current_heading', 6, 2)->nullable();
            // Metres travelled along the route geometry, from route_stops.
            $table->unsignedInteger('route_offset_meters')->default(0);

            $table->foreignId('current_stop_id')->nullable()->constrained('bus_stops')->nullOnDelete();
            $table->foreignId('next_stop_id')->nullable()->constrained('bus_stops')->nullOnDelete();
            $table->unsignedSmallInteger('next_stop_sequence')->nullable();
            $table->unsignedInteger('distance_to_next_stop')->nullable();
            $table->unsignedInteger('eta_next_stop_seconds')->nullable();

            $table->unsignedSmallInteger('passenger_count')->default(0);
            $table->unsignedSmallInteger('peak_passenger_count')->default(0);
            $table->unsignedInteger('boarding_count')->default(0);
            $table->unsignedBigInteger('revenue_minor')->default(0);

            $table->unsignedInteger('distance_meters')->default(0);
            $table->decimal('average_speed_kmh', 6, 2)->nullable();

            $table->boolean('is_off_route')->default(false);
            $table->boolean('is_idle')->default(false);
            $table->timestamp('last_ping_at')->nullable();

            $table->timestamps();

            $table->index(['city_id', 'status']);
            $table->index(['bus_id', 'status']);
            $table->index(['route_id', 'status']);
            $table->index(['driver_id', 'started_at']);
            $table->index(['status', 'last_ping_at']);
        });

        Schema::create('trip_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();

            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('speed_kmh', 6, 2)->nullable();
            $table->decimal('heading', 6, 2)->nullable();
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->decimal('altitude', 8, 2)->nullable();

            // Matched against the route at ingest time so replays are cheap.
            $table->unsignedInteger('route_offset_meters')->nullable();
            $table->decimal('route_deviation_meters', 8, 2)->nullable();
            $table->foreignId('nearest_stop_id')->nullable()->constrained('bus_stops')->nullOnDelete();

            // Device clock; may differ from recorded_at if the app buffered.
            $table->timestamp('recorded_at');
            $table->timestamp('received_at');

            $table->index(['trip_id', 'recorded_at']);
            $table->index(['bus_id', 'recorded_at']);
            $table->index('recorded_at');
        });

        Schema::create('trip_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48);
            $table->foreignId('bus_stop_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['trip_id', 'type']);
            $table->index(['type', 'occurred_at']);
        });

        // Rolling statistics that feed the v1 ETA engine. One row per
        // (route segment, day type, hour) bucket, updated incrementally.
        Schema::create('segment_travel_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_stop_id')->constrained('bus_stops')->cascadeOnDelete();
            $table->foreignId('to_stop_id')->constrained('bus_stops')->cascadeOnDelete();

            $table->string('day_type', 16);
            $table->unsignedTinyInteger('hour_bucket');

            $table->unsignedInteger('sample_count')->default(0);
            $table->decimal('mean_seconds', 10, 2)->default(0);
            // Welford's M2 accumulator, so variance updates in constant time.
            $table->decimal('m2', 16, 4)->default(0);
            $table->unsignedInteger('p50_seconds')->nullable();
            $table->unsignedInteger('p85_seconds')->nullable();
            $table->decimal('traffic_factor', 5, 3)->default(1.0);

            $table->timestamp('last_sample_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['route_id', 'from_stop_id', 'to_stop_id', 'day_type', 'hour_bucket'],
                'segment_stats_bucket_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segment_travel_stats');
        Schema::dropIfExists('trip_events');
        Schema::dropIfExists('trip_locations');
        Schema::dropIfExists('trips');
    }
};
