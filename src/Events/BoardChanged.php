<?php

namespace Packstub\Kanban\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast (queued) after a board changed: a move, a card action, a create. The
 * other tabs listening on the board's channel reload; the tab that made the change
 * recognises its own origin token and ignores it. Only sent when the board
 * broadcast()s; CardMoved stays the event apps listen to on the server.
 */
class BoardChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $channel,
        public readonly string $event,
        public readonly ?string $board = null,
        public readonly ?string $id = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?string $origin = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    /** The name the browser listens for; Echo wants the leading dot, the broadcaster does not. */
    public function broadcastAs(): string
    {
        return ltrim($this->event, '.');
    }

    /** @return array<string, string|null> */
    public function broadcastWith(): array
    {
        return [
            'board' => $this->board,
            'id' => $this->id,
            'from' => $this->from,
            'to' => $this->to,
            'origin' => $this->origin,
        ];
    }
}
