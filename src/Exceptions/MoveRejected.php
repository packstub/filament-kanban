<?php

namespace Packstub\Kanban\Exceptions;

use RuntimeException;

/** Thrown to refuse a move: the card goes back where it was and the message is shown to the user. */
class MoveRejected extends RuntimeException {}
