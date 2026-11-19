<?php

namespace App\Exceptions;

use RuntimeException;

class NoCurrentSchoolException extends RuntimeException
{
    public function __construct(string $message = 'No current school is set in the tenant context.')
    {
        parent::__construct($message);
    }
}
