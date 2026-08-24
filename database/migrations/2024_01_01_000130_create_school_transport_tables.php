<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School transport.
 *
 * The shape of the product, because the tables only make sense against it: a
 * parent signs a contract with an approved company for one child, for a school
 * year; the company puts that contract on a route; the route runs twice a day
 * with a vehicle and a driver; and on each run the driver checks each child on
 * and off. The parent watches the vehicle while their own child's run is
 * happening, and only then.
 *
 * Three decisions are worth stating up front.
 *
 * A contract is per child, not per family. Siblings ride the same van from the
 * same door, but they are picked up separately, they are absent separately, and
 * one of them leaving mid-year must not cancel the other's place.
 *
 * A trip is a run, not a timetable. `school_trips` rows are created for a
 * specific date and direction, which is what makes "where is the van right now"
 * answerable and what gives each check-in something concrete to hang from.
 *
 * Attendance rows are created when the run is created, one per child expected
 * on it, and start as `pending`. A child nobody touched is then visibly
 * unaccounted for rather than silently absent from the data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('code', 32)->unique();
            // Companies are approved before parents can see them at all: a
            // list of unvetted strangers offering to drive children is not a
            // marketplace, it is a hazard.
            $table->string('status', 32)->default('pending_approval');

            $table->string('registration_number', 64)->nullable();
            $table->string('license_number', 64)->nullable();
            $table->date('license_expires_at')->nullable();

            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->text('description')->nullable();

            $table->unsignedSmallInteger('commission_bps')->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bank_account_holder')->nullable();

            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('contract_count')->default(0);

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['city_id', 'status']);
        });

        Schema::create('school_company_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // manager | dispatcher
            $table->string('role', 32)->default('dispatcher');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['school_company_id', 'user_id']);
        });

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 32)->nullable();
            // girls | boys | mixed
            $table->string('gender', 16)->default('mixed');
            // primary | middle | high | other
            $table->string('level', 16)->default('primary');

            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('phone', 20)->nullable();

            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['city_id', 'name']);
            $table->index(['city_id', 'is_active']);
        });

        Schema::create('school_vehicles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('school_company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            $table->string('plate', 32);
            $table->string('model')->nullable();
            $table->string('color', 32)->nullable();
            $table->unsignedSmallInteger('manufacture_year')->nullable();
            $table->unsignedTinyInteger('capacity')->default(15);

            $table->string('status', 32)->default('active');
            $table->boolean('has_supervisor')->default(false);
            $table->boolean('has_air_conditioning')->default(true);
            $table->boolean('has_seatbelts')->default(true);

            $table->date('insurance_expires_at')->nullable();
            $table->date('inspection_due_at')->nullable();

            $table->timestamp('last_ping_at')->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['city_id', 'plate']);
            $table->index(['school_company_id', 'status']);
        });

        Schema::create('school_students', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('guardian_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();

            $table->string('first_name', 60);
            $table->string('last_name', 60);
            $table->string('national_code', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('grade', 32)->nullable();
            $table->string('classroom', 32)->nullable();
            $table->string('gender', 16)->nullable();

            // Where the van actually stops, which is not always the home
            // address: a safe kerb thirty metres away is a different place.
            $table->string('pickup_address')->nullable();
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();

            // Carried so a driver has it in the moment it matters, not in a
            // file somebody would have to go and find.
            $table->text('medical_notes')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();

            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['guardian_user_id', 'is_active']);
            $table->index(['city_id', 'school_id']);
        });

        Schema::create('school_service_routes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('school_company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 32)->nullable();
            // morning | afternoon | both
            $table->string('shift', 16)->default('both');

            $table->foreignId('school_vehicle_id')->nullable()->constrained('school_vehicles')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedTinyInteger('capacity')->default(15);
            // 1..7, Saturday first, matching the school week here.
            $table->json('days_of_week')->nullable();
            $table->time('pickup_starts_at')->nullable();
            $table->time('dropoff_starts_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['school_company_id', 'is_active']);
            $table->index(['city_id', 'is_active']);
        });

        Schema::create('school_service_contracts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 40)->unique();

            $table->foreignId('school_student_id')->constrained('school_students')->cascadeOnDelete();
            $table->foreignId('guardian_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('school_company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            // Null until the company puts the contract on a route, which is the
            // step that turns an agreement into a seat in a specific van.
            $table->foreignId('school_service_route_id')->nullable()
                ->constrained('school_service_routes')->nullOnDelete();

            $table->string('status', 32)->default('requested');
            // to_school | from_school | both
            $table->string('direction', 16)->default('both');

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->json('days_of_week')->nullable();

            $table->string('pickup_address')->nullable();
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();

            // What the family pays per cycle, and how often. Held on the
            // contract because it is what was agreed, not what the company's
            // price list happens to say later.
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->string('payment_cycle', 16)->default('monthly');
            $table->unsignedSmallInteger('discount_bps')->default(0);

            $table->text('guardian_note')->nullable();
            $table->text('company_note')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['guardian_user_id', 'status']);
            $table->index(['school_company_id', 'status']);
            $table->index(['school_service_route_id', 'status']);
        });

        Schema::create('school_contract_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('school_service_contract_id')->constrained('school_service_contracts')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40)->unique();
            $table->string('status', 32)->default('pending');

            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_on');

            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('commission_amount')->default(0);

            $table->foreignId('wallet_transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['school_service_contract_id', 'status']);
            $table->index(['city_id', 'status']);
        });

        Schema::create('school_trips', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('school_service_route_id')->constrained('school_service_routes')->cascadeOnDelete();
            $table->foreignId('school_company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_vehicle_id')->nullable()->constrained('school_vehicles')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            $table->date('service_date');
            $table->string('direction', 16);
            $table->string('status', 32)->default('scheduled');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->unsignedSmallInteger('expected_count')->default(0);
            $table->unsignedSmallInteger('picked_up_count')->default(0);
            $table->unsignedSmallInteger('dropped_off_count')->default(0);
            $table->unsignedSmallInteger('absent_count')->default(0);

            $table->unsignedInteger('distance_meters')->default(0);

            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 10, 7)->nullable();

            $table->timestamps();

            // One run per route, date and direction. The unique key is what
            // stops a second tap on "start" creating a parallel run with half
            // the children on it.
            $table->unique(['school_service_route_id', 'service_date', 'direction'], 'school_trips_run_unique');
            $table->index(['city_id', 'service_date']);
            $table->index(['driver_id', 'status']);
        });

        Schema::create('school_trip_students', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('school_trip_id')->constrained('school_trips')->cascadeOnDelete();
            $table->foreignId('school_student_id')->constrained('school_students')->cascadeOnDelete();
            $table->foreignId('school_service_contract_id')->nullable()
                ->constrained('school_service_contracts')->nullOnDelete();

            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('status', 32)->default('pending');

            $table->string('pickup_address')->nullable();
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();

            $table->timestamp('picked_up_at')->nullable();
            $table->decimal('picked_up_lat', 10, 7)->nullable();
            $table->decimal('picked_up_lng', 10, 7)->nullable();

            $table->timestamp('dropped_off_at')->nullable();
            $table->decimal('dropped_off_lat', 10, 7)->nullable();
            $table->decimal('dropped_off_lng', 10, 7)->nullable();

            // Who tapped the button, because "the child was marked absent" is a
            // claim somebody made and a parent will ask who.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();

            $table->timestamps();

            $table->unique(['school_trip_id', 'school_student_id']);
            $table->index(['school_trip_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_trip_students');
        Schema::dropIfExists('school_trips');
        Schema::dropIfExists('school_contract_invoices');
        Schema::dropIfExists('school_service_contracts');
        Schema::dropIfExists('school_service_routes');
        Schema::dropIfExists('school_students');
        Schema::dropIfExists('school_vehicles');
        Schema::dropIfExists('schools');
        Schema::dropIfExists('school_company_staff');
        Schema::dropIfExists('school_companies');
    }
};
