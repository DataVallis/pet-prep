<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ExportOpenApiSpecCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'openapi:export';

    /**
     * The console command description.
     */
    protected $description = 'Export the OpenAPI 3.1 JSON specification to storage/api-docs/openapi.json';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Fetch the OpenAPI spec from the Scramble docs endpoint
        $response = Http::get(config('app.url').'/docs/api.json');

        if (! $response->successful()) {
            $this->error('Failed to fetch OpenAPI spec from /docs/api.json');
            $this->error('Status: '.$response->status());

            return self::FAILURE;
        }

        $spec = $response->body();

        // Ensure the storage directory exists
        Storage::makeDirectory('api-docs');

        // Write the spec to storage
        Storage::put('api-docs/openapi.json', $spec);

        $this->info('OpenAPI spec exported to storage/app/api-docs/openapi.json');

        return self::SUCCESS;
    }
}
