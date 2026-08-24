<?php

namespace App\Console\Commands;

use App\Domain\SchoolTransport\Models\SchoolServiceContract;
use App\Domain\SchoolTransport\Services\SchoolInvoiceService;
use Illuminate\Console\Command;

/**
 * Issue the invoices due this period, and age the ones nobody paid.
 *
 * Issuing is idempotent on the period, so a family cannot be billed twice for
 * one month by a scheduler that ran twice or by an operator who also clicked
 * the button in the panel.
 */
class BillSchoolContractsCommand extends Command
{
    protected $signature = 'school:contracts:bill';

    protected $description = 'Issue school service invoices for the current period and mark overdue ones';

    public function handle(SchoolInvoiceService $invoices): int
    {
        $issued = 0;

        SchoolServiceContract::query()
            ->live()
            ->with('company')
            ->chunkById(200, function ($contracts) use ($invoices, &$issued): void {
                foreach ($contracts as $contract) {
                    // A contract with no fee named yet is one the company has
                    // not answered; there is nothing to bill.
                    if ($contract->payableAmount() <= 0) {
                        continue;
                    }

                    $invoice = $invoices->issueFor($contract);

                    if ($invoice->wasRecentlyCreated) {
                        $issued++;
                    }
                }
            });

        $overdue = $invoices->markOverdue();

        $this->info("Issued $issued invoice(s); marked $overdue overdue.");

        return self::SUCCESS;
    }
}
