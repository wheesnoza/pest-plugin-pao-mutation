<?php

declare(strict_types=1);

namespace Fixture;

final class Calculator
{
    public function add(int $left, int $right): int
    {
        if ($left === 0) {
            return $right;
        }

        return $left + $right;
    }
}
