<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();

            $table->string('code', 32);
            $table->string('name');
            $table->string('name_en')->nullable();

            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);

            $table->string('address')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();

            // Radius in metres treated as "at this stop" for arrival detection.
            $table->unsignedSmallInteger('geofence_radius')->default(60);

            $table->boolean('has_shelter')->default(false);
            $table->boolean('is_accessible')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('is_active')->default(true);

            // Provenance keeps demo geometry from being shown as official data.
            $table->string('provenance', 16)->default('sample');
            $table->string('source_ref')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['city_id', 'code']);
            // Composite index drives the bounding-box "stops near me" query.
            $table->index(['city_id', 'is_active', 'lat', 'lng'], 'bus_stops_spatial_idx');
        });

        Schema::create('bus_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('color', 7)->default('#16a34a');

            $table->string('origin_label')->nullable();
            $table->string('destination_label')->nullable();

            $table->unsignedSmallInteger('typical_duration_minutes')->nullable();
            $table->unsignedSmallInteger('headway_minutes')->nullable();

            $table->time('service_start')->nullable();
            $table->time('service_end')->nullable();

            $table->boolean('is_active')->default(true);
            $table->string('provenance', 16)->default('sample');
            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['city_id', 'code']);
            $table->index(['city_id', 'is_active']);
        });

        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_line_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // outbound | inbound | loop
            $table->string('direction', 16)->default('outbound');

            $table->foreignId('origin_stop_id')->nullable()->constrained('bus_stops')->nullOnDelete();
            $table->foreignId('destination_stop_id')->nullable()->constrained('bus_stops')->nullOnDelete();

            // Ordered [[lat,lng], ...] shape of the driven path.
            $table->json('geometry')->nullable();
            $table->unsignedInteger('distance_meters')->default(0);
            $table->unsignedSmallInteger('typical_duration_minutes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('provenance', 16)->default('sample');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['bus_line_id', 'direction', 'is_active']);
        });

        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_stop_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');
            // Metres from the route origin measured along the geometry; this is
            // what makes "distance to next stop" a subtraction, not a search.
            $table->unsignedInteger('distance_from_start')->default(0);
            $table->unsignedSmallInteger('travel_time_from_previous')->nullable();
            $table->unsignedSmallInteger('dwell_seconds')->default(20);

            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_timepoint')->default(false);
            $table->boolean('allows_boarding')->default(true);
            $table->boolean('allows_alighting')->default(true);

            $table->timestamps();

            $table->unique(['route_id', 'sequence']);
            $table->index(['bus_stop_id', 'route_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stops');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('bus_lines');
        Schema::dropIfExists('bus_stops');
    }
};
