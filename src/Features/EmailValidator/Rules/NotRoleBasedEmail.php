<?php

namespace RiseTechApps\RiseTools\Features\EmailValidator\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RiseTechApps\RiseTools\Features\EmailValidator\EmailValidator;

/**
 * Recusa e-mails genéricos (contato@, suporte@, noreply@...).
 *
 * 'email' => ['required', 'email', new NotRoleBasedEmail()]
 */
class NotRoleBasedEmail implements ValidationRule
{
    public function __construct(
        protected string $message = 'The :attribute must be a personal e-mail address.'
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && app(EmailValidator::class)->isRoleBased($value)) {
            $fail($this->message);
        }
    }
}
