<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidInvitationException extends RuntimeException
{
    /**
     * Create a generic invalid invitation exception without exposing internal state.
     */
    public function __construct(?string $message = null)
    {
        parent::__construct(
            $message ?? 'This invitation is no longer valid. Ask your school to send a new one.',
        );
    }
}
