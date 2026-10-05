<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Reads the named fal.ai model profiles from config/media.php (M4-02).
 */
class MediaProfiles
{
    public function get(string $kind, string $key): ModelProfile
    {
        $config = config("media.profiles.{$kind}.{$key}");

        if (! in_array($kind, [ModelProfile::KIND_IMAGE, ModelProfile::KIND_VIDEO], true) || ! is_array($config)) {
            throw new InvalidArgumentException("Unknown media profile {$kind}.{$key}.");
        }

        return ModelProfile::fromConfig($kind, $key, $config);
    }

    public function image(string $key): ModelProfile
    {
        return $this->get(ModelProfile::KIND_IMAGE, $key);
    }

    public function video(string $key): ModelProfile
    {
        return $this->get(ModelProfile::KIND_VIDEO, $key);
    }

    /**
     * @return array<string, ModelProfile>
     */
    public function all(string $kind): array
    {
        $profiles = [];

        foreach (array_keys((array) config("media.profiles.{$kind}", [])) as $key) {
            $profiles[$key] = $this->get($kind, (string) $key);
        }

        return $profiles;
    }

    /**
     * Profiles the AI Lab may call: enabled and not excluded from the lab.
     *
     * @return array<string, ModelProfile>
     */
    public function forLab(string $kind): array
    {
        return array_filter($this->all($kind), fn (ModelProfile $p) => $p->enabled && $p->lab);
    }

    /** Production defaults (David, 2026-10-05). */
    public const DEFAULT_REFERENCE_IMAGE = 'nano_banana_pro';

    public const DEFAULT_STATE_VIDEO = 'kling_v3_pro';

    public function referenceImage(): ModelProfile
    {
        return $this->image($this->configured(ModelProfile::KIND_IMAGE, 'media.reference_image_profile', self::DEFAULT_REFERENCE_IMAGE));
    }

    public function stateVideo(): ModelProfile
    {
        return $this->video($this->configured(ModelProfile::KIND_VIDEO, 'media.state_video_profile', self::DEFAULT_STATE_VIDEO));
    }

    /**
     * The configured profile key, or the shipped default when the env names a
     * profile that no longer exists (e.g. an old server .env still saying
     * `kling_v16_legacy`, removed 2026-10-05) — logged, never a crash.
     */
    private function configured(string $kind, string $configKey, string $default): string
    {
        $key = (string) config($configKey, $default);

        if (! is_array(config("media.profiles.{$kind}.{$key}"))) {
            Log::error('MediaProfiles: unknown profile configured, using the default', ['config' => $configKey, 'profile' => $key, 'default' => $default]);

            return $default;
        }

        return $key;
    }
}
