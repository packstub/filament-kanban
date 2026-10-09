<?php

namespace Packstub\Kanban\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** BoardChanged sent during the request instead of through the queue (Board::broadcastNow()). */
class BoardChangedNow extends BoardChanged implements ShouldBroadcastNow {}
