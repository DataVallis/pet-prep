<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Sign-up (M2-10a): the e-mail address belongs to an account created by a
 * concurrent request after validation passed (lost race on the unique index).
 */
class EmailTakenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The email has already been taken.');
    }
}
