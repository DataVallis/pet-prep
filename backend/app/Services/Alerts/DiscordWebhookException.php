<?php

namespace App\Services\Alerts;

use RuntimeException;

/** A failed Discord post (M5-T19). The message never contains the webhook URL. */
class DiscordWebhookException extends RuntimeException {}
