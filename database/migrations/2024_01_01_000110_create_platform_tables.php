<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's database notification channel.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_unread_idx');
        });

        // Web Push (PWA) endpoints, one row per browser/device.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 512);
            $table->string('public_key', 255)->nullable();
            $table->string('auth_token', 255)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('device_name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->unique(['user_id', 'endpoint'], 'push_subs_user_endpoint_unique');
        });

        // Passenger subscriptions to "tell me when line X nears stop Y".
        Schema::create('stop_arrival_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_stop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_line_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('notify_minutes_before')->default(5);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'bus_stop_id', 'bus_line_id'], 'arrival_subs_unique');
            $table->index(['bus_stop_id', 'is_active']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();

            // Dotted action name, e.g. "fleet.bus.qr_regenerated".
            $table->string('action', 96);
            $table->nullableMorphs('auditable');

            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Tracks every network data import so a bad file can be traced back.
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // stops | lines | routes | route_stops
            $table->string('entity', 32);
            // csv | geojson
            $table->string('format', 16);
            $table->string('source_name')->nullable();
            $table->string('provenance', 16)->default('sample');

            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->json('errors')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['city_id', 'entity']);
        });

        // Per-day rollups powering the dashboard without scanning fact tables.
        Schema::create('daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->unsignedInteger('trips_count')->default(0);
            $table->unsignedInteger('active_buses')->default(0);
            $table->unsignedInteger('active_drivers')->default(0);
            $table->unsignedInteger('boardings_count')->default(0);
            $table->unsignedInteger('unique_passengers')->default(0);
            $table->unsignedInteger('new_users')->default(0);

            $table->unsignedBigInteger('fare_revenue')->default(0);
            $table->unsignedBigInteger('topup_amount')->default(0);
            $table->unsignedBigInteger('merchant_volume')->default(0);
            $table->unsignedBigInteger('refund_amount')->default(0);
            $table->unsignedInteger('transactions_count')->default(0);

            $table->unsignedInteger('complaints_opened')->default(0);
            $table->unsignedInteger('complaints_resolved')->default(0);

            $table->decimal('avg_passengers_per_trip', 8, 2)->default(0);
            $table->decimal('avg_speed_kmh', 6, 2)->nullable();
            $table->decimal('avg_eta_error_seconds', 8, 2)->nullable();

            $table->timestamps();

            $table->unique(['city_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_metrics');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('stop_arrival_subscriptions');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notifications');
    }
};
