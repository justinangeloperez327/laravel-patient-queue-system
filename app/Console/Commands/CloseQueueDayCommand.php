<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\QueueEventType;
use App\Enums\QueueTicketStatus;
use App\Models\QueueTicket;
use App\Services\Queue\QueueEventRecorder;
use App\Support\ClinicTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * End-of-day handling (proposed; confirm with the clinic before production):
 * waiting, called and skipped tickets from earlier clinic days are cancelled
 * with a queue event. Tickets still being served are reported, never
 * changed automatically. Open visits remain for reception to close.
 */
final class CloseQueueDayCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'queue:close-day';

    /**
     * @var string
     */
    protected $description = 'Cancel unfinished queue tickets from previous clinic days';

    public function handle(QueueEventRecorder $events): int
    {
        $today = ClinicTime::today()->toDateString();
        $cancelled = 0;

        QueueTicket::query()
            ->whereDate('queue_date', '<', $today)
            ->inStatus([QueueTicketStatus::Waiting, QueueTicketStatus::Called, QueueTicketStatus::Skipped])
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use ($events, &$cancelled): void {
                DB::transaction(function () use ($id, $events, &$cancelled): void {
                    $ticket = QueueTicket::query()->whereKey($id)->lockForUpdate()->first();

                    if ($ticket === null || ! $ticket->status->canTransitionTo(QueueTicketStatus::Cancelled)) {
                        return;
                    }

                    $previous = $ticket->status;
                    $ticket->status = QueueTicketStatus::Cancelled;
                    $ticket->save();
                    $events->record($ticket, QueueEventType::Cancelled, null, $previous, 'End of day', ['automatic' => true]);
                    $cancelled++;
                });
            });

        $serving = QueueTicket::query()
            ->whereDate('queue_date', '<', $today)
            ->where('status', QueueTicketStatus::Serving->value)
            ->pluck('ticket_number');

        $this->components->info("Cancelled {$cancelled} ticket(s) from previous days.");

        if ($serving->isNotEmpty()) {
            $this->components->warn('Still being served from previous days: '.$serving->implode(', '));
        }

        return self::SUCCESS;
    }
}
