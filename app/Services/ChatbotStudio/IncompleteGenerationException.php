<?php

namespace App\Services\ChatbotStudio;

use Illuminate\Validation\ValidationException;

class IncompleteGenerationException extends ValidationException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct(validator([], []));
        $this->validator->errors()->add('generation', $message);
    }
}
