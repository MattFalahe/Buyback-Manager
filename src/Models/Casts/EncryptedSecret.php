<?php

namespace BuybackManager\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * A secret kept encrypted with the app key: the Janice key and the Discord
 * webhook URLs, either of which lets whoever reads the database spend the
 * corporation's Janice quota or post as its webhook.
 *
 * A value saved before encryption, or loaded back from a dump taken then,
 * still reads as it was and is encrypted the next time it is saved. One
 * encrypted under a different app key cannot be read, and reads as null so
 * it is asked for again rather than sent on as ciphertext.
 */
class EncryptedSecret implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            return self::looksEncrypted($value) ? null : $value;
        }
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return Crypt::encryptString((string) $value);
    }

    /** Laravel's payload: base64 of a JSON object carrying iv, value and mac. */
    public static function looksEncrypted(string $value): bool
    {
        $payload = json_decode((string) base64_decode($value, true), true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
}
