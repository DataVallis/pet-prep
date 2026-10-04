<?php

namespace App\Services\Media;

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

    public function referenceImage(): ModelProfile
    {
        return $this->image((string) config('media.reference_image_profile', 'flux_schnell'));
    }

    public function stateVideo(): ModelProfile
    {
        return $this->video((string) config('media.state_video_profile', 'kling_v16_legacy'));
    }
}
