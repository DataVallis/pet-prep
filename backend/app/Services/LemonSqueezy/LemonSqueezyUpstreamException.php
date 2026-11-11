<?php

namespace App\Services\LemonSqueezy;

use RuntimeException;

/**
 * Lemon Squeezy could not answer usefully: connection error, timeout, 429 or 5xx
 * (CONTRACT L2 / L16). The message never carries the license key or the API key.
 */
class LemonSqueezyUpstreamException extends RuntimeException {}
