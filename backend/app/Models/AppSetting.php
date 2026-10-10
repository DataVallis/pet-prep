<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One product switch (M5-R06-09). Read and write only through
 * App\Services\AppSettingsService (cache + audit).
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'value', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
