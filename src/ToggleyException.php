<?php

namespace Toggley;

final class ToggleyException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'error')
    {
        parent::__construct($message);
    }
}
