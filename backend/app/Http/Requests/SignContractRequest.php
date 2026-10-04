<?php

namespace App\Http\Requests;

use App\Models\Pet;
use App\Models\PetContract;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/child/contract (M1-07, PRODUCT_SPEC §3): the child's finger
 * signature, either
 *  - `svg_path`: SVG path data (commands M L H V C S Q T A Z and numbers
 *    only, must start with a move), at most 20,000 characters; or
 *  - `png`: base64 PNG (an optional `data:image/png;base64,` prefix is
 *    stripped), at most 100 KB decoded, PNG signature checked.
 * No external calls; `signed_at` is set by the server.
 */
class SignContractRequest extends FormRequest
{
    public const MAX_SVG_PATH_LENGTH = 20000;

    public const MAX_PNG_BYTES = 102400;

    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    private const DATA_URI_PREFIX = 'data:image/png;base64,';

    public function authorize(): bool
    {
        return $this->user()?->can('useChildApi', Pet::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Raw length bound before any decoding (base64 is 4/3 of the bytes).
        $maxRaw = max(self::MAX_SVG_PATH_LENGTH, (int) ceil(self::MAX_PNG_BYTES * 4 / 3) + strlen(self::DATA_URI_PREFIX) + 4);

        return [
            'signature_format' => ['required', 'string', 'in:'.implode(',', PetContract::FORMATS)],
            'signature' => ['required', 'string', 'max:'.$maxRaw],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $format = (string) $this->input('signature_format');
                $signature = (string) $this->input('signature');

                $error = $format === PetContract::FORMAT_SVG_PATH
                    ? $this->svgPathError($signature)
                    : $this->pngError($signature);

                if ($error !== null) {
                    $validator->errors()->add('signature', $error);
                }
            },
        ];
    }

    /**
     * The signature as stored: trimmed SVG path data, or base64 PNG without
     * a data-URI prefix and without whitespace.
     */
    public function normalizedSignature(): string
    {
        $signature = (string) $this->validated('signature');

        if ($this->validated('signature_format') === PetContract::FORMAT_SVG_PATH) {
            return trim($signature);
        }

        return $this->pngBase64($signature);
    }

    private function svgPathError(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || strlen($path) > self::MAX_SVG_PATH_LENGTH) {
            return 'The SVG path must be between 1 and '.self::MAX_SVG_PATH_LENGTH.' characters.';
        }

        if (preg_match('/^[Mm][MmLlHhVvCcSsQqTtAaZz0-9eE.,+\-\s]*$/', $path) !== 1) {
            return 'The SVG path may only contain path commands and numbers and must start with a move (M).';
        }

        return null;
    }

    private function pngError(string $signature): ?string
    {
        $base64 = $this->pngBase64($signature);
        $bytes = base64_decode($base64, true);

        if ($base64 === '' || $bytes === false) {
            return 'The PNG signature must be valid base64.';
        }

        if (strlen($bytes) > self::MAX_PNG_BYTES) {
            return 'The PNG signature may not be larger than '.(self::MAX_PNG_BYTES / 1024).' KB.';
        }

        if (! str_starts_with($bytes, self::PNG_MAGIC)) {
            return 'The signature is not a PNG image.';
        }

        return null;
    }

    private function pngBase64(string $signature): string
    {
        $signature = trim($signature);
        if (str_starts_with($signature, self::DATA_URI_PREFIX)) {
            $signature = substr($signature, strlen(self::DATA_URI_PREFIX));
        }

        return preg_replace('/\s+/', '', $signature) ?? '';
    }
}
