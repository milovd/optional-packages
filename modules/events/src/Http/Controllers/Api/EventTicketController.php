<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Http\Controllers\Api;

use Agovena\Modules\Events\Models\EventTicket;
use Illuminate\Http\JsonResponse;

final class EventTicketController
{
    public function index(): JsonResponse
    {
        $customer = authenticated_customer();
        $tickets = EventTicket::query()
            ->with(['event', 'performance', 'ticketType'])
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'void')
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => $tickets->getCollection()->map(fn (EventTicket $ticket): array => $this->serialize($ticket))->values(),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    public function show(string $token): JsonResponse
    {
        $customer = authenticated_customer();
        $ticket = EventTicket::query()
            ->with(['event', 'performance', 'ticketType'])
            ->where('token', strtolower($token))
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        return response()->json(['data' => $this->serialize($ticket)]);
    }

    /** @return array<string, mixed> */
    private function serialize(EventTicket $ticket): array
    {
        return [
            'number' => $ticket->number,
            'token' => $ticket->token,
            'status' => $ticket->status->value,
            'event' => $ticket->event?->name,
            'venue' => $ticket->performance?->venue ?: $ticket->event?->venue,
            'starts_at' => $ticket->performance?->starts_at?->toIso8601String(),
            'ticket_type' => $ticket->ticketType?->name,
            'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
        ];
    }
}
