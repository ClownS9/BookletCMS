<?php

use App\Booklet\Data\Blueprint;
use App\Booklet\Facades\Booklet;
use Illuminate\Database\Migrations\Migration;

/**
 * Books made before these fields existed: add a standfirst and cover caption
 * to the Post blueprint, and a standfirst, header image and "people behind
 * it" switch to the Page blueprint. Fields already there are left alone.
 * Goes through Booklet, so it works with either driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $this->add('collections.posts', 'post', [
                ['handle' => 'excerpt', 'field' => ['type' => 'textarea', 'display' => 'Standfirst', 'instructions' => 'A line under the title that sets up the story.']],
                ['handle' => 'cover_caption', 'field' => ['type' => 'text', 'display' => 'Cover caption', 'instructions' => 'Caption and credit, shown under the picture.']],
            ]);

            $this->add('collections.pages', 'page', [
                ['handle' => 'excerpt', 'field' => ['type' => 'textarea', 'display' => 'Standfirst', 'instructions' => 'One line on what this page is about.']],
                ['handle' => 'cover', 'field' => ['type' => 'assets', 'container' => 'media', 'max_files' => 1, 'display' => 'Header image']],
                ['handle' => 'show_people', 'field' => ['type' => 'toggle', 'display' => 'Show the people behind it', 'instructions' => 'Everyone in the book, with their bylines.']],
            ]);
        } catch (Throwable) {
            // No book yet (setup hasn't run): the starter blueprints already have these.
        }
    }

    protected function add(string $namespace, string $handle, array $fields): void
    {
        /** @var Blueprint|null $blueprint */
        $blueprint = Booklet::blueprints()->findIn($namespace, $handle);

        if (! $blueprint) {
            return;
        }

        $existing = collect($blueprint->fields())->pluck('handle')->filter()->all();
        $tabs = $blueprint->tabs();
        $tab = isset($tabs['sidebar']) ? 'sidebar' : array_key_last($tabs);

        if ($tab === null) {
            return;
        }

        foreach ($fields as $field) {
            if (! in_array($field['handle'], $existing, true)) {
                $tabs[$tab]['sections'][0]['fields'][] = $field;
            }
        }

        $blueprint->set('tabs', $tabs)->save();
    }

    public function down(): void
    {
        // Fields are kept: removing them could hide words people wrote.
    }
};
