<?php

namespace Nikaia\TranslationSheet\Translation;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Nikaia\TranslationSheet\Commands\Output;
use Nikaia\TranslationSheet\Spreadsheet;
use Nikaia\TranslationSheet\Util;

class Writer
{
    use Output;

    /** @var Collection */
    protected $translations;

    /** @var Spreadsheet */
    protected $spreadsheet;

    /** @var Filesystem */
    protected $files;

    /** @var Application */
    protected $app;

    public function __construct(Spreadsheet $spreadsheet, Filesystem $files, Application $app)
    {
        $this->spreadsheet = $spreadsheet;
        $this->files = $files;
        $this->app = $app;

        $this->nullOutput();
    }

    public function setTranslations($translations)
    {
        $this->translations = $translations;

        return $this;
    }

    public function write()
    {
        $this
            ->groupTranslationsByFile()
            ->map(function ($items, $sourceFile) {
                $this->writeFile(
                    $this->app->make('path.lang') . '/' . $sourceFile,
                    $items
                );
            });
    }

    protected function writeFile($file, $items)
    {
        $this->output->writeln('  ' . $file);

        $content = "<?php\n\nreturn " . Util::varExport($items) . ";\n";

        if (!$this->files->isDirectory($dir = dirname($file))) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $this->files->put($file, $content);
    }

    protected function groupTranslationCollectionFilter($collection)
    {
        $collection_result = new Collection();

        foreach ($collection as $language_file => $entries) {

            $new_language_file = [];

            foreach ($entries as $entry_key => $entry) {

                if (is_array($entry)) {
                    /* level 1 */
                    foreach ($entry as $entry_d1_k => $entry_d1_v) {

                        if (is_array($entry_d1_v)) {
                            /* level 2 */
                            foreach ($entry_d1_v as $entry_d2_k => $entry_d2_v) {
                                if (is_array($entry_d2_v)) {
                                    /* level 3 */
                                    foreach ($entry_d2_v as $entry_d3_k => $entry_d3_v) {

                                        if (is_array($entry_d3_v)) { } else {

                                            $new_language_file[$entry_key . "." . $entry_d1_k . "." . $entry_d2_k . "." . $entry_d3_k] = $entry_d3_v;
                                        }
                                    }
                                    /* end level 3 */
                                } else {

                                    $new_language_file[$entry_key . "." . $entry_d1_k . "." . $entry_d2_k] = $entry_d2_v;
                                }
                            }

                            /* end level 2 */
                        } else {

                            $new_language_file[$entry_key . "." . $entry_d1_k] = $entry_d1_v;
                        }
                    }
                    /* end level 1 */
                } else {
                    $new_language_file[$entry_key] = $entry;
                }
            }
            $collection_result[$language_file] = $new_language_file;
        }
        // \Log::info($collection_result->toJson());
        return $collection_result;
    }

    protected function groupTranslationsByFile()
    {
        $items = $this
            ->translations
            ->groupBy('sourceFile')
            ->map(function ($fileTranslations) {
                return $this->buildTranslationsForFile($fileTranslations);
            });

        // flatten does not seem to work for every case. !!! refactor !!!
        $result = [];
        foreach ($items as $subitems) {

            $result = array_merge($result, $subitems);
        }
        $collection_result = new Collection($result);

        return Writer::groupTranslationCollectionFilter($collection_result);
    }

    protected function buildTranslationsForFile($fileTranslations)
    {
        $files = [];
        $locales = $this->spreadsheet->getLocales();

        foreach ($locales as $locale) {
            foreach ($fileTranslations as $translation) {

                // We will only write non empty translations
                // For instance, we have `app.title` that is the same for each locale,
                // We dont want to translate it to every locale, and prefer letting
                // Laravel default back to the default locale.
                if (!isset($translation[$locale])) {
                    continue;
                }

                $localeFile = str_replace('{locale}/', $locale . '/', $translation['sourceFile']);
                if (empty($files[$localeFile])) {
                    $files[$localeFile] = [];
                }

                Arr::set($files[$localeFile], $translation['key'], $translation[$locale]);
            }
        }

        return $files;
    }
}
