<?php

namespace App\Listeners;

use App\Events\FolioClosed;
use App\Services\Fiscal\FiscalService;

/**
 * Closing a settled folio queues its FIRS e-invoice. Unconfigured
 * branches are skipped silently, so fiscal emission stays opt-in per
 * environment and never blocks checkout.
 */
class QueueEinvoiceForClosedFolio
{
    public function __construct(private FiscalService $fiscal) {}

    public function handle(FolioClosed $event): void
    {
        $this->fiscal->queueFor($event->folio);
    }
}
