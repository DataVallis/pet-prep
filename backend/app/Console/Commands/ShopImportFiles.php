<?php

namespace App\Console\Commands;

use App\Services\Shop\ShopFiles;
use App\Support\WebsiteLocale;
use Illuminate\Console\Command;

/**
 * M6-S09: copy the sold PDFs into the private shop disk. Default layout = the output of
 * `npm run kit:build` in pet-prep-website: `out/promise-kit/petprep-promise-kit-<locale>.pdf`
 * (product promise_kit) and `…/petprep-promise-sample-<locale>.pdf` (the free sample).
 */
class ShopImportFiles extends Command
{
    protected $signature = 'shop:import-files {dir : Folder with the built PDFs} {--dry-run}';

    protected $description = 'Import the promise kit PDFs (all website languages) into the private shop storage';

    public function handle(): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/');
        $map = ['promise_kit' => 'petprep-promise-kit-%s.pdf', ShopFiles::SAMPLE => 'petprep-promise-sample-%s.pdf'];
        $n = 0;
        foreach ($map as $product => $pattern) {
            foreach (WebsiteLocale::ALL as $locale) {
                $file = $dir.'/'.sprintf($pattern, $locale);
                if (! is_file($file)) {
                    $this->warn("missing: {$file}");

                    continue;
                }
                if (! str_starts_with((string) file_get_contents($file, false, null, 0, 5), '%PDF-')) {
                    $this->error("not a PDF: {$file}");

                    continue;
                }
                if (! $this->option('dry-run')) {
                    ShopFiles::disk()->put(ShopFiles::path($product, $locale), (string) file_get_contents($file));
                }
                $this->line(($this->option('dry-run') ? 'would import ' : 'imported ').$product.' '.$locale);
                $n++;
            }
        }
        $this->info("{$n} file(s)");

        return self::SUCCESS;
    }
}
