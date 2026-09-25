<?php

declare(strict_types=1);

use Fixture\Calculator;

covers(Calculator::class);

it('adds two numbers', function (): void {
    expect((new Calculator)->add(2, 3))->toBe(5);
});

if (getenv('FIXTURE_FAIL_INITIAL_TEST')) {
    it('fails before mutation', function (): void {
        expect((new Calculator)->add(2, 3))->toBe(6);
    });
}
