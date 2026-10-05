<?php

namespace App\Services\Push;

/**
 * Expo refused a send because its messages belong to more than one Expo
 * project (`PUSH_TOO_MANY_EXPERIENCE_IDS`, e.g. tokens of an old and a new
 * build). `groups` = the tokens per project from Expo's `details`; the caller
 * sends each group in its own request.
 */
class ExpoMixedProjectsException extends ExpoPushException
{
    /**
     * @param  array<string, list<string>>  $groups  project → tokens
     */
    public function __construct(public readonly array $groups)
    {
        parent::__construct('Expo: messages for more than one project in one request', retryable: false);
    }
}
