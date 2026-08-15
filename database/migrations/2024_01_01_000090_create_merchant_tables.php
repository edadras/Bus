<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('code', 32)->unique();
            $table->string('type', 32)->default('other');
            $table->string('status', 32)->default('pending_approval');

            $table->string('national_id', 32)->nullable();
            $table->string('registration_number', 32)->nullable();

            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->string('logo_path')->nullable();
            $table->text('description')->nullable();

            // Commission withheld from every accepted payment, in basis points.
            $table->unsignedSmallInteger('commission_bps')->default(150);
            $table->string('settlement_cycle', 16)->default('weekly');
            $table->string('iban', 34)->nullable();
            $table->string('bank_account_holder')->nullable();

            $table->boolean('allows_refund')->default(true);
            $table->unsignedBigInteger('max_transaction_amount')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['city_id', 'status']);
            $table->index(['type', 'status']);
        });

        Schema::create('merchant_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // manager | cashier
            $table->string('role', 32)->default('cashier');
            $table->boolean('can_refund')->default(false);
            $table->boolean('can_view_reports')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['merchant_id', 'user_id']);
        });

        Schema::create('merchant_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('public_id', 32)->unique();
            // HMAC key behind the terminal's rotating QR, encrypted at rest.
            $table->text('secret');

            $table->string('location_label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->index(['merchant_id', 'is_active']);
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 32)->unique();
            $table->string('status', 16)->default('draft');

            $table->date('period_start');
            $table->date('period_end');

            $table->unsignedInteger('transaction_count')->default(0);
            $table->unsignedBigInteger('gross_amount')->default(0);
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->unsignedBigInteger('refund_amount')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->string('currency', 3)->default('IRR');

            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['status', 'period_end']);
        });

        Schema::create('merchant_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashier_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->string('currency', 3)->default('IRR');

            $table->string('reference', 32)->unique();
            $table->string('description')->nullable();

            $table->foreignId('settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refund_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();

            $table->timestamps();

            $table->index(['merchant_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['merchant_id', 'settlement_id']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_transactions');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('merchant_terminals');
        Schema::dropIfExists('merchant_staff');
        Schema::dropIfExists('merchants');
    }
};
