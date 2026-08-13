<?php

namespace BuybackManager\Services\Pricing;

use Exception;

/**
 * Raised when an upstream price fetch fails outright rather than simply
 * returning no price for a type.
 *
 * The distinction matters: a type that genuinely has no market orders is
 * worth zero, but a provider that is down, rate limiting us, or rejecting
 * our API key is worth *unknown*. Treating the second case as zero silently
 * quotes items at nothing, so the fetchers throw this instead and let the
 * caller fall back to cached prices or refuse to quote.
 */
class PriceFetchException extends Exception
{
}
