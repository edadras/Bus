<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('country_code', 2)->default('IR');
            $table->string('province')->nullable();
            $table->string('timezone', 64)->default('Asia/Tehran');
            $table->string('currency', 3)->default('IRR');
            $table->string('locale', 8)->default('fa');

            // Map default viewport for every client of this city.
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->unsignedTinyInteger('default_zoom')->default(12);

            // Optional bounding box used to reject out-of-city coordinates.
            $table->decimal('bbox_min_lat', 10, 7)->nullable();
            $table->decimal('bbox_min_lng', 10, 7)->nullable();
            $table->decimal('bbox_max_lat', 10, 7)->nullable();
            $table->decimal('bbox_max_lng', 10, 7)->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_launched')->default(false);
            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'is_launched']);
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            // GeoJSON polygon ring used for zone based fares.
            $table->json('boundary')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['city_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
        Schema::dropIfExists('cities');
    }
};
