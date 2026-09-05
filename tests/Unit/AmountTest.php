<?php

use AjayMahato\Esewa\Exceptions\EsewaException;
use AjayMahato\Esewa\Support\Amount;

it('normalises every amount shape to two decimals', function (mixed $input, string $expected) {
    expect(Amount::normalize($input))->toBe($expected);
})->with([
    'integer' => [1000, '1000.00'],
    'float' => [1000.5, '1000.50'],
    'float with paisa' => [1234.56, '1234.56'],
    'numeric string' => ['1000', '1000.00'],
    'decimal string' => ['1000.5', '1000.50'],
    'thousands separator' => ['1,234.50', '1234.50'],
    'padded' => ['  1234.50  ', '1234.50'],
    'rupee prefix' => ['Rs. 1,234.50', '1234.50'],
    'NPR prefix' => ['NPR 250', '250.00'],
    'zero' => [0, '0.00'],
]);

it('keeps paisa that an integer cast would destroy', function () {
    // The previous implementation cast to int, silently turning every order
    // ending in paisa into a short payment.
    expect(Amount::normalize('1234.50'))->toBe('1234.50')
        ->and((int) '1234.50')->toBe(1234);
});

it('refuses values that are not amounts', function (mixed $input) {
    Amount::normalize($input);
})->with([
    'empty string' => '',
    'null' => null,
    'letters' => 'abc',
    'boolean' => true,
    'array' => [[[1, 2]]],
])->throws(EsewaException::class);

it('compares amounts by value, not by formatting', function () {
    expect(Amount::equals('1000', 1000))->toBeTrue()
        ->and(Amount::equals('1000.0', '1,000.00'))->toBeTrue()
        ->and(Amount::equals(1000.0, '1000'))->toBeTrue()
        ->and(Amount::equals('1000.00', '1000.01'))->toBeFalse()
        ->and(Amount::equals('1000', 'not a number'))->toBeFalse();
});

it('sums without accumulating floating point drift', function () {
    // 0.1 + 0.2 !== 0.3 in binary floating point.
    expect(Amount::sum('0.10', '0.20'))->toBe('0.30')
        ->and(Amount::sum(...array_fill(0, 10, '0.10')))->toBe('1.00')
        ->and(Amount::sum(1000, '50.25', 0, '9.75'))->toBe('1060.00');
});

it('reports whether an amount is chargeable', function () {
    expect(Amount::isPositive('0.01'))->toBeTrue()
        ->and(Amount::isPositive(0))->toBeFalse()
        ->and(Amount::isPositive('-5'))->toBeFalse()
        ->and(Amount::isPositive('nonsense'))->toBeFalse();
});

it('returns null instead of throwing when asked to try', function () {
    expect(Amount::tryNormalize('nope'))->toBeNull()
        ->and(Amount::tryNormalize('12.5'))->toBe('12.50');
});

it('keeps large amounts exact instead of letting a float eat the paisa', function () {
    // A float carries about 15 significant digits, so routing the string
    // through one turns ...456.78 into ...456.75. The digits are rounded here
    // directly so the amount that reaches eSewa is the amount that was asked
    // for, however large.
    expect(Amount::normalize('1234567890123456.78'))->toBe('1234567890123456.78')
        ->and(number_format((float) '1234567890123456.78', 2, '.', ''))->toBe('1234567890123456.75');
});

it('rounds paisa half up, away from zero', function (string $input, string $expected) {
    expect(Amount::normalize($input))->toBe($expected);
})->with([
    'exact half rounds up' => ['1.005', '1.01'],
    'below half rounds down' => ['1.004', '1.00'],
    'above half rounds up' => ['1.006', '1.01'],
    'carries through nines' => ['9.999', '10.00'],
    'negative half rounds away from zero' => ['-1.005', '-1.01'],
    'never yields negative zero' => ['-0.001', '0.00'],
    'leading decimal point' => ['.5', '0.50'],
    'exponent notation' => ['1.5e3', '1500.00'],
    'negative exponent' => ['15e-2', '0.15'],
    'strips leading zeros' => ['0001000.5', '1000.50'],
]);

it('sums amounts without float drift', function () {
    expect(Amount::sum('0.1', '0.2'))->toBe('0.30')
        ->and(Amount::sum('1234567890123.45', '0.55'))->toBe('1234567890124.00')
        ->and(Amount::sum('100', '-100'))->toBe('0.00');
});
