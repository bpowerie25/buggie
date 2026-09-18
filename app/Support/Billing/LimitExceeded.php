<?php

namespace App\Support\Billing;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 402 Payment Required, which is exactly what this is — as opposed to 403, which would
 * suggest the person is not allowed to do the thing at all.
 */
class LimitExceeded extends HttpException
{
    public function __construct(
        public readonly string $limit,
        public readonly int $allowed,
        string $message,
    ) {
        parent::__construct(402, $message);
    }

    public static function projects(int $allowed): self
    {
        return new self('projects', $allowed, "Your plan includes {$allowed} projects. Upgrade to add more.");
    }

    public static function members(int $allowed): self
    {
        return new self('members', $allowed, "Your plan includes {$allowed} people. Upgrade to invite more.");
    }

    public static function reports(int $allowed): self
    {
        return new self('reports_per_month', $allowed, "This workspace has reached its monthly limit of {$allowed} reports.");
    }
}
