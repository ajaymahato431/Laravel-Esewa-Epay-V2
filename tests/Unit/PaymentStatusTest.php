<?php

use AjayMahato\Esewa\Enums\PaymentStatus;

it('never mistakes an unknown status for a paid one', function (mixed $input) {
    // "We do not recognise this" must resolve to PENDING, never COMPLETE.
    expect(PaymentStatus::fromResponse($input))->toBe(PaymentStatus::PENDING);
})->with([
    'null' => null,
    'empty' => '',
    'unknown word' => 'SETTLED',
    'array' => [[['status' => 'COMPLETE']]],
    'boolean' => true,
]);

it('parses the statuses eSewa documents, case insensitively', function () {
    expect(PaymentStatus::fromResponse('COMPLETE'))->toBe(PaymentStatus::COMPLETE)
        ->and(PaymentStatus::fromResponse('complete'))->toBe(PaymentStatus::COMPLETE)
        ->and(PaymentStatus::fromResponse('  Canceled '))->toBe(PaymentStatus::CANCELED)
        ->and(PaymentStatus::fromResponse(PaymentStatus::AMBIGUOUS))->toBe(PaymentStatus::AMBIGUOUS);
});

it('classifies each status', function () {
    expect(PaymentStatus::COMPLETE->isComplete())->toBeTrue()
        ->and(PaymentStatus::PENDING->isPending())->toBeTrue()
        ->and(PaymentStatus::AMBIGUOUS->isPending())->toBeTrue()
        ->and(PaymentStatus::CANCELED->isFailed())->toBeTrue()
        ->and(PaymentStatus::NOT_FOUND->isFailed())->toBeTrue()
        ->and(PaymentStatus::FULL_REFUND->isRefunded())->toBeTrue()
        ->and(PaymentStatus::PARTIAL_REFUND->isRefunded())->toBeTrue();
});

it('keeps ambiguous payments out of the terminal set so they get re-checked', function () {
    // eSewa resolves AMBIGUOUS later, so reconciliation must keep polling it.
    expect(PaymentStatus::AMBIGUOUS->isTerminal())->toBeFalse()
        ->and(PaymentStatus::PENDING->isTerminal())->toBeFalse()
        ->and(PaymentStatus::COMPLETE->isTerminal())->toBeTrue()
        ->and(PaymentStatus::CANCELED->isTerminal())->toBeTrue()
        ->and(PaymentStatus::FULL_REFUND->isTerminal())->toBeTrue();
});

it('gives every status a customer-facing label', function () {
    foreach (PaymentStatus::cases() as $status) {
        expect($status->label())->toBeString()->not->toBeEmpty();
    }
});

it('lists the documented status values', function () {
    expect(PaymentStatus::values())->toEqualCanonicalizing([
        'PENDING', 'COMPLETE', 'FULL_REFUND', 'PARTIAL_REFUND', 'AMBIGUOUS', 'NOT_FOUND', 'CANCELED',
    ]);
});
