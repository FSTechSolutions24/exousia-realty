<?php

namespace App\Services;

use InvalidArgumentException;

class EgyptianPhoneNormalizer
{
    public function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '0020')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '20')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0'.$digits;
        }

        if (! preg_match('/^01[0125][0-9]{8}$/', $digits)) {
            throw new InvalidArgumentException('Enter a valid Egyptian mobile number.');
        }

        return '+20'.substr($digits, 1);
    }
}
