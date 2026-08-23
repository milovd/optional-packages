<?php

declare(strict_types=1);

namespace Agovena\Modules\Events;

use Agovena\Modules\Events\Enums\EventTicketStatus;
use Agovena\Modules\Events\Models\EventPerformance;
use Agovena\Modules\Events\Models\EventTicket;
use Agovena\Modules\Events\Models\EventTicketType;
use App\Agovena\Notifications\SendsCataloguedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EventService
{
    public function remainingForPerformance(EventPerformance $performance): int
    {
        $sold = EventTicket::query()
            ->where('performance_id', $performance->id)
            ->where('status', '!=', EventTicketStatus::Void->value)
            ->count();

        return max(0, (int) $performance->capacity - $sold);
    }

    public function remainingForType(EventTicketType $type): int
    {
        if ($type->capacity === null) {
            $performance = $type->performance ?? EventPerformance::query()->find($type->performance_id);

            return $performance instanceof EventPerformance ? $this->remainingForPerformance($performance) : 0;
        }

        $sold = EventTicket::query()
            ->where('ticket_type_id', $type->id)
            ->where('status', '!=', EventTicketStatus::Void->value)
            ->count();

        return max(0, (int) $type->capacity - $sold);
    }

    public function ticketTypeForProduct(Product $product): ?EventTicketType
    {
        return EventTicketType::query()
            ->with('performance')
            ->where('product_id', $product->id)
            ->first();
    }

    public function assertAvailable(Product $product, int $quantity): void
    {
        $type = $this->ticketTypeForProduct($product);
        if ($type === null) {
            throw ValidationException::withMessages([
                'cart' => __('events::errors.product_not_mapped'),
            ]);
        }

        $remaining = min(
            $this->remainingForType($type),
            $type->performance instanceof EventPerformance
                ? $this->remainingForPerformance($type->performance)
                : 0,
        );

        if ($quantity > $remaining) {
            throw ValidationException::withMessages([
                'cart' => __('events::errors.sold_out'),
            ]);
        }
    }

    public function issueFromPaidOrder(Order $order): void
    {
        $order->loadMissing('items');
        $issued = 0;

        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $product = Product::query()->with('capabilities')->find($item->product_id);
            if ($product === null || ! $product->hasCapability('event_ticket')) {
                continue;
            }

            $type = $this->ticketTypeForProduct($product);
            if ($type === null || $type->performance_id === null) {
                continue;
            }

            $existing = EventTicket::query()->where('order_item_id', $item->id)->count();
            $needed = max(0, $item->quantity - $existing);
            if ($needed === 0) {
                continue;
            }

            $this->assertAvailable($product, $needed);

            for ($i = 0; $i < $needed; $i++) {
                EventTicket::query()->create([
                    'number' => $this->generateNumber(),
                    'token' => bin2hex(random_bytes(32)),
                    'event_id' => $type->event_id,
                    'performance_id' => $type->performance_id,
                    'ticket_type_id' => $type->id,
                    'product_id' => $product->id,
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'customer_id' => $order->customer_id,
                    'customer_email' => $order->customer_email,
                    'customer_name' => $order->customer_name,
                    'status' => EventTicketStatus::Issued,
                ]);
                $issued++;
            }
        }

        if ($issued > 0) {
            app(SendsCataloguedMail::class)->toOrderCustomer(
                $order->customer_id,
                (string) $order->customer_email,
                'event_ticket_issued',
                [
                    'name' => (string) $order->customer_name,
                    'number' => $order->number,
                    'detail' => $order->number,
                    'action_url' => Route::has('customer.event-tickets')
                        ? route('customer.event-tickets')
                        : url('/'),
                    'action_label' => __('notifications.event_ticket_issued.action'),
                ],
            );
        }
    }

    /**
     * Accepts the 64-character ticket token or the public ticket number.
     *
     * @return array{ticket: EventTicket, already: bool}
     */
    public function checkIn(string $code, ?User $staff = null, ?int $performanceId = null): array
    {
        $code = trim($code);
        if ($code === '') {
            throw ValidationException::withMessages([
                'code' => __('events::errors.invalid_code'),
            ]);
        }

        return DB::transaction(function () use ($code, $staff, $performanceId): array {
            $query = EventTicket::query()->lockForUpdate();
            $token = strtolower($code);
            /** @var EventTicket|null $ticket */
            $ticket = preg_match('/^[a-f0-9]{64}$/', $token) === 1
                ? $query->where('token', $token)->first()
                : $query->where('number', strtoupper($code))->first();
            if ($ticket === null) {
                throw ValidationException::withMessages([
                    'code' => __('events::errors.not_found'),
                ]);
            }

            if ($performanceId !== null && (int) $ticket->performance_id !== $performanceId) {
                throw ValidationException::withMessages([
                    'code' => __('events::errors.wrong_performance'),
                ]);
            }

            if ($ticket->status === EventTicketStatus::Void) {
                throw ValidationException::withMessages([
                    'code' => __('events::errors.void'),
                ]);
            }

            if ($ticket->status === EventTicketStatus::CheckedIn) {
                return ['ticket' => $ticket, 'already' => true];
            }

            $ticket->status = EventTicketStatus::CheckedIn;
            $ticket->checked_in_at = now();
            $ticket->checked_in_by = $staff?->id;
            $ticket->save();

            return ['ticket' => $ticket->fresh() ?? $ticket, 'already' => false];
        });
    }

    private function generateNumber(): string
    {
        do {
            $number = 'TCK-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (EventTicket::query()->where('number', $number)->exists());

        return $number;
    }
}
