<?php

namespace App\Domain\Taxi\Services;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Enums\SettlementStatus;
use App\Domain\Taxi\Models\TaxiRide;
use App\Domain\Taxi\Models\TaxiSettlement;
use App\Domain\Wallet\Services\WalletService;
use App\Notifications\TaxiSettlementNotification;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paying a taxi driver out.
 *
 * Fares land in the driver's own wallet as they are taken, so a payout is not
 * a calculation of what they earned — it is a transfer of what is already
 * theirs. What the settlement adds is the claim: each ride is attached to
 * exactly one payout, which is what makes the run safe to repeat after a
 * failure. That is the same guarantee the merchant payout leans on, and the
 * reason this table exists rather than a second sum over the same rides.
 */
class TaxiSettlementService
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * The driver asks to be paid.
     *
     * Requested by the driver themselves from their app, which is why the
     * period is bounded by what has actually been claimed rather than by dates
     * they choose: a driver picking their own window could otherwise claim the
     * same week twice.
     */
    public function request(
        Driver $driver,
        User $requestedBy,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): TaxiSettlement {
        $from ??= now()->subMonth();
        $to ??= now();

        return DB::transaction(function () use ($driver, $requestedBy, $from, $to): TaxiSettlement {
            $settlement = TaxiSettlement::create([
                'driver_id' => $driver->id,
                'city_id' => $driver->city_id,
                'reference' => $this->generateReference(),
                'status' => SettlementStatus::Requested,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'requested_by' => $requestedBy->id,
            ]);

            // Locked so two taps cannot claim the same rides into two payouts.
            $claimable = TaxiRide::query()
                ->where('driver_id', $driver->id)
                ->paid()
                ->whereNull('taxi_settlement_id')
                ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->lockForUpdate()
                ->get();

            if ($claimable->isEmpty()) {
                throw DomainException::make('nothing_to_settle', 422, [
                    'driver_uuid' => $driver->uuid,
                ]);
            }

            TaxiRide::whereIn('id', $claimable->pluck('id'))
                ->update(['taxi_settlement_id' => $settlement->id]);

            $gross = (int) $claimable->sum('fare_amount');
            $commission = (int) $claimable->sum('commission_amount');

            $settlement->forceFill([
                'ride_count' => $claimable->count(),
                'gross_amount' => $gross,
                'commission_amount' => $commission,
                'net_amount' => max(0, $gross - $commission),
            ])->save();

            $minimum = (int) config('taxi.settlement.minimum_amount');

            if ($settlement->net_amount < $minimum) {
                // Refusing here rather than at approval keeps the driver's app
                // honest: they are told now, not after a day of waiting.
                throw DomainException::make('settlement_below_minimum', 422, [
                    'minimum' => $minimum,
                    'net_amount' => $settlement->net_amount,
                ]);
            }

            return $settlement->fresh();
        });
    }

    /**
     * Approve and post the payout.
     *
     * The driver's wallet is debited to the settlement payable account at the
     * moment of approval, not when the bank transfer clears: the money has left
     * the platform's float either way, and pretending otherwise would leave a
     * balance the driver could spend twice.
     */
    public function approve(TaxiSettlement $settlement, User $approver): TaxiSettlement
    {
        if ($settlement->status !== SettlementStatus::Requested) {
            throw DomainException::make('settlement_not_approvable', 422, [
                'status' => $settlement->status->value,
            ]);
        }

        $approved = DB::transaction(function () use ($settlement, $approver): TaxiSettlement {
            $driverUser = $settlement->driver?->user;

            if ($driverUser === null) {
                throw DomainException::make('taxi_driver_has_no_account', 500);
            }

            $wallet = $this->wallets->forUser($driverUser);

            if ($wallet->balance < $settlement->net_amount) {
                throw DomainException::make('settlement_exceeds_balance', 422, [
                    'balance' => $wallet->balance,
                    'net_amount' => $settlement->net_amount,
                ]);
            }

            $transaction = $this->wallets->postSettlement(
                merchantWallet: $wallet,
                amount: $settlement->net_amount,
                idempotencyKey: 'taxi-settlement:'.$settlement->uuid,
                subject: $settlement,
                initiatedBy: $approver,
            );

            $settlement->forceFill([
                'status' => SettlementStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'wallet_transaction_id' => $transaction->id,
            ])->save();

            return $settlement->fresh();
        });

        // After the commit: an approval that rolled back must not have pinged.
        $this->tellDriver($approved, 'approved');

        return $approved;
    }

    public function markPaid(TaxiSettlement $settlement, string $paymentReference): TaxiSettlement
    {
        if ($settlement->status !== SettlementStatus::Approved) {
            throw DomainException::make('settlement_not_approved', 422);
        }

        $settlement->forceFill([
            'status' => SettlementStatus::Paid,
            'paid_at' => now(),
            'payment_reference' => $paymentReference,
        ])->save();

        $this->tellDriver($settlement, 'paid');

        return $settlement;
    }

    /** Reject, and release the claimed rides back into the pool. */
    public function reject(TaxiSettlement $settlement, User $actor, string $reason): TaxiSettlement
    {
        if ($settlement->status === SettlementStatus::Paid) {
            throw DomainException::make('settlement_already_paid', 422);
        }

        $rejected = DB::transaction(function () use ($settlement, $actor, $reason): TaxiSettlement {
            TaxiRide::where('taxi_settlement_id', $settlement->id)
                ->update(['taxi_settlement_id' => null]);

            $settlement->forceFill([
                'status' => SettlementStatus::Rejected,
                'rejection_reason' => $reason,
                'approved_by' => $actor->id,
            ])->save();

            return $settlement->fresh();
        });

        $this->tellDriver($rejected, 'rejected');

        return $rejected;
    }

    /** The decision belongs to the driver, whichever way it went. */
    private function tellDriver(TaxiSettlement $settlement, string $event): void
    {
        $settlement->loadMissing('driver.user');
        $settlement->driver?->user?->notify(TaxiSettlementNotification::forSettlement($settlement, $event));
    }

    /** What a driver could ask to be paid right now. */
    public function pendingBalance(Driver $driver): array
    {
        $row = TaxiRide::query()
            ->where('driver_id', $driver->id)
            ->paid()
            ->whereNull('taxi_settlement_id')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(fare_amount),0) as gross, COALESCE(SUM(commission_amount),0) as commission')
            ->first();

        $gross = (int) $row->gross;
        $commission = (int) $row->commission;

        return [
            'ride_count' => (int) $row->cnt,
            'gross_amount' => $gross,
            'commission_amount' => $commission,
            'net_amount' => max(0, $gross - $commission),
            'minimum_amount' => (int) config('taxi.settlement.minimum_amount'),
        ];
    }

    private function generateReference(): string
    {
        $prefix = (string) config('taxi.settlement.reference_prefix', 'TXS');

        do {
            $candidate = $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (TaxiSettlement::where('reference', $candidate)->exists());

        return $candidate;
    }
}
