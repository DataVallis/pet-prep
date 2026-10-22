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

    /** Image-to-image profile for stage growth (M5-R01), or null when disabled / unknown. */
    public function stageEdit(): ?ModelProfile
    {
        $key = (string) config('media.stage_edit_profile', 'nano_banana_pro_edit');

        if (! is_array(config("media.profiles.image.{$key}"))) {
            Log::error('MediaProfiles: unknown stage edit profile configured, using text-to-image', ['profile' => $key]);

            return null;
        }

        $profile = $this->image($key);

        return $profile->enabled ? $profile : null;
    }

    /**
     * M5-T08: image-to-image profile for the state KEY IMAGES (Nano Banana Pro
     * Edit, David 2026-10-21), or null when disabled / unknown — then no key
     * images, loops or transitions are made (the pre-M5-T08 media set).
     */
    public function keyframe(): ?ModelProfile
    {
        $key = (string) config('media.state_media.keyframe_profile', 'nano_banana_pro_edit');

        if (! is_array(config("media.profiles.image.{$key}"))) {
            // Read on every media payload: log once per process, not per request.
            static $logged = [];
            if (! isset($logged[$key])) {
                $logged[$key] = true;
                Log::error('MediaProfiles: unknown key image profile configured, no key images', ['profile' => $key]);
            }

            return null;
        }

        $profile = $this->image($key);

        return $profile->enabled ? $profile : null;
    }

    /**
     * M5-T09 / T10 (David 2026-10-21): the state video profile with the clip length of
     * a purpose (media.state_media.clip_seconds: state / transition 5 s, school / game
     * 3 s). A length the model does not accept keeps the profile's own length (logged).
     */
    public function clipVideo(string $purpose): ModelProfile
    {
        $profile = $this->stateVideo();
        $seconds = (int) config("media.state_media.clip_seconds.{$purpose}", 0);

        if ($seconds <= 0 || $seconds === $profile->durationSeconds) {
            return $profile;
        }

        if (! $profile->supportsDuration($seconds)) {
            static $logged = [];
            if (! isset($logged[$profile->key.$seconds])) {
                $logged[$profile->key.$seconds] = true;
                Log::error('MediaProfiles: clip length not supported by the video profile, using its default', ['profile' => $profile->key, 'seconds' => $seconds, 'purpose' => $purpose]);
            }

            return $profile;
        }

        return $profile->withDuration($seconds);
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
