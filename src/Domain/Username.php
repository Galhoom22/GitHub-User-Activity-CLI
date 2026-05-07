<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Username
{
    public function __construct(public string $value)
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,39}$/', $value)) {
            throw new \InvalidArgumentException("Invalid GitHub username: {$value}");
        }
    }
}
