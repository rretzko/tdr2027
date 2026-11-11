<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A voice-part change VoicePartAuditService refused — the message is
 * user-facing and shown as-is in a toast.
 */
class VoicePartChangeRefusedException extends RuntimeException {}
