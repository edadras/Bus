<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Polymorphic owner: user | merchant | system.
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id')->nullable();
            // Stable handle for system accounts, e.g. "system:fare-revenue".
            $table->string('account_ref', 64)->nullable()->unique();

            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();

            $table->string('currency', 3)->default('IRR');
            // Cached balance in minor units. The ledger remains the source of
            // truth; this column is a materialised sum guarded by row locks.
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('pending_balance')->default(0);
            // Monotonic counter incremented on every posting; lets a client
            // detect a stale read without comparing balances.
            $table->unsignedBigInteger('version')->default(0);

            $table->string('status', 16)->default('active');
            $table->bigInteger('daily_spend_limit')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->string('frozen_reason')->nullable();

            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'currency'], 'wallets_owner_currency_unique');
            $table->index(['owner_type', 'status']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('type', 32);
            $table->string('status', 16)->default('pending');
            $table->string('currency', 3)->default('IRR');
            // Gross amount of the transaction, always positive.
            $table->unsignedBigInteger('amount');

            // Idempotency: a retry with the same key returns the original
            // transaction instead of creating a second one.
            $table->string('idempotency_key', 96)->nullable()->unique();

            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();

            // What the money was for (trip, merchant charge, payment, ...).
            $table->nullableMorphs('subject');

            // Set when this transaction reverses another one.
            $table->foreignId('reverses_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();

            $table->string('description')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['created_at']);
        });

        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            // debit reduces the wallet, credit increases it. The signed sum of
            // every entry belonging to one transaction must equal zero.
            $table->string('direction', 8);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('IRR');

            // Running balance after this entry; makes statements reproducible
            // and lets an auditor verify the chain without re-summing.
            $table->bigInteger('balance_after');
            $table->unsignedBigInteger('sequence');

            $table->string('description')->nullable();
            $table->json('metadata')->nullable();

            // Ledger rows are immutable: created_at only, no updated_at.
            $table->timestamp('created_at')->nullable();

            $table->unique(['wallet_id', 'sequence']);
            $table->index(['wallet_id', 'created_at']);
            $table->index('wallet_transaction_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->string('gateway', 32);
            $table->string('status', 16)->default('initiated');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('IRR');

            $table->string('gateway_reference')->nullable();
            $table->string('gateway_authority')->nullable();
            $table->string('card_mask', 32)->nullable();
            $table->json('gateway_payload')->nullable();
            $table->string('failure_reason')->nullable();

            $table->string('return_url')->nullable();
            $table->string('client_ip', 45)->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['gateway', 'gateway_reference']);
        });

        // Generic idempotency store for non-wallet endpoints (scan, boarding).
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 96);
            $table->string('scope', 64);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['scope', 'key']);
            $table->index('expires_at');
        });

        Schema::create('fare_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 48);
            // bus | merchant — the surface this rule prices.
            $table->string('context', 16)->default('bus');

            // Narrowing conditions. Null means "any".
            $table->foreignId('bus_line_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('from_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->foreignId('to_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            // regular | student | senior | disabled | child
            $table->string('passenger_type', 32)->nullable();

            $table->time('valid_from_time')->nullable();
            $table->time('valid_to_time')->nullable();
            // JSON array of ISO day numbers (1=Mon .. 7=Sun); null = every day.
            $table->json('valid_days')->nullable();
            $table->date('valid_from_date')->nullable();
            $table->date('valid_to_date')->nullable();

            $table->unsignedBigInteger('base_fare');
            $table->unsignedBigInteger('per_km_fare')->default(0);
            $table->unsignedBigInteger('min_fare')->nullable();
            $table->unsignedBigInteger('max_fare')->nullable();
            // Multiplier applied last, e.g. 0.5 for a student discount.
            $table->decimal('multiplier', 5, 3)->default(1.0);

            // Higher priority wins when several rules match.
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['city_id', 'code']);
            $table->index(['city_id', 'context', 'is_active', 'priority'], 'fare_rules_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_rules');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
