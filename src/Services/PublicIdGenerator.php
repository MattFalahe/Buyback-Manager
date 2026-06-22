<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackOffer;

/**
 * 8-character unguessable public IDs for BuybackOffer URLs.
 *
 * Friendlier to paste into EVE chat or quote on Discord than a 36-char
 * UUID. Uses a disambiguated alphabet (no I/l/1/0/O confusion).
 *
 * 32^8 ≈ 1.1 trillion combinations — collision risk vanishing for any
 * realistic install. Generator retries up to 5 times on the off chance
 * Str::random produces a value that already exists; throws after that
 * (signals either a vanishingly bad random source or a packed DB).
 */
class PublicIdGenerator
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const LENGTH = 8;
    private const PREFIX = 'bb-';
    private const MAX_ATTEMPTS = 5;

    /**
     * Generate a new public_id unique against BuybackOffer.
     */
    public static function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = self::PREFIX . self::randomToken(self::LENGTH);
            if (! BuybackOffer::where('public_id', $candidate)->exists()) {
                return $candidate;
            }
        }
        throw new \RuntimeException(
            'PublicIdGenerator: ' . self::MAX_ATTEMPTS . ' collisions in a row. ' .
            'Check the random source — odds of legitimate collision are vanishingly small.'
        );
    }

    private static function randomToken(int $length): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
