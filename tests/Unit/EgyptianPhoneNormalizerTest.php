<?php

namespace Tests\Unit;

use App\Services\EgyptianPhoneNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class EgyptianPhoneNormalizerTest extends TestCase
{
    #[DataProvider('validPhones')]
    public function test_it_normalizes_egyptian_mobile_formats(string $input, string $expected): void
    {
        $this->assertSame($expected, (new EgyptianPhoneNormalizer())->normalize($input));
    }

    public static function validPhones(): array
    {
        return [['010 1234 5678', '+201012345678'], ['+20 11 1234 5678', '+201112345678'], ['00201212345678', '+201212345678'], ['1512345678', '+201512345678']];
    }

    public function test_it_rejects_invalid_numbers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EgyptianPhoneNormalizer())->normalize('12345');
    }
}
