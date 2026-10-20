<?php

namespace RiseTechApps\RiseTools\Features\EmailValidator\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RiseTechApps\RiseTools\Features\EmailValidator\EmailValidator;

/**
 * 'email' => ['required', 'email', new NotDisposableEmail()]
 */
class NotDisposableEmail implements ValidationRule
{
    public function __construct(
        protected string $message = 'The :attribute must not be a disposable e-mail address.'
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && app(EmailValidator::class)->isDisposable($value)) {
            $fail($this->message);
        }
    }
}
