<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Turns PostgreSQL constraint violations into validation errors.
 *
 * Application validation catches most problems, but only the database can
 * settle races between concurrent requests; this maps the named constraint
 * that fired back onto the offending form field.
 */
final class ConstraintViolation
{
    /**
     * Unique (23505) and exclusion (23P01) violations.
     */
    private const array HANDLED_STATES = ['23505', '23P01'];

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @param  array<string, array{0: string, 1: string}>  $constraints  constraint name => [field, message]
     * @return TReturn
     *
     * @throws ValidationException
     */
    public static function guard(callable $callback, array $constraints): mixed
    {
        try {
            return $callback();
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;

            if (! in_array($state, self::HANDLED_STATES, true)) {
                throw $exception;
            }

            foreach ($constraints as $constraint => [$field, $message]) {
                if (str_contains($exception->getMessage(), '"'.$constraint.'"')) {
                    throw ValidationException::withMessages([$field => $message]);
                }
            }

            throw $exception;
        }
    }
}
