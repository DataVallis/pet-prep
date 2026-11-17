<?php

namespace App\Services\Shop;

use RuntimeException;

/** Stripe could not be reached or refused the call (M6-S09). Never carries a key. */
class StripeException extends RuntimeException {}
