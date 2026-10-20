<?php

namespace App\Booklet\Data;

/**
 * A blueprint, in Statamic's format:
 *
 *   title: Post
 *   tabs:
 *     main:
 *       display: Main
 *       sections:
 *         - fields:
 *             - handle: title
 *               field: { type: text, display: Title, validate: [required] }
 *             - import: seo        # pull in a fieldset
 *
 * Namespaces mirror Statamic: "collections.posts", "taxonomies.tags",
 * "navigation.header", "globals.site", "assets.media", "forms.contact".
 */
class Blueprint extends Item
{
    public static function repositoryName(): string
    {
        return 'blueprints';
    }

    public function namespace(): string
    {
        return (string) $this->attribute('namespace', '');
    }

    public function handle(): string
    {
        return $this->attribute('handle');
    }

    public function title(): string
    {
        return $this->get('title', ucfirst($this->handle()));
    }

    public function tabs(): array
    {
        return (array) $this->get('tabs', []);
    }

    /**
     * Every field in order, across tabs and sections, as
     * ['handle' => …, 'field' => [...]] (fieldset imports left as-is).
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->tabs() as $tab) {
            foreach ((array) ($tab['sections'] ?? []) as $section) {
                foreach ((array) ($section['fields'] ?? []) as $field) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    public function field(string $handle): ?array
    {
        foreach ($this->fields() as $field) {
            if (($field['handle'] ?? null) === $handle) {
                return $field['field'] ?? [];
            }
        }

        return null;
    }

    /**
     * Add a field to the end of a tab's first section (creating them if needed).
     */
    public function addField(string $handle, array $config, string $tab = 'main'): static
    {
        $tabs = $this->tabs();
        $tabs[$tab] ??= ['display' => ucfirst($tab), 'sections' => [['fields' => []]]];
        $tabs[$tab]['sections'] ??= [['fields' => []]];
        $tabs[$tab]['sections'][0]['fields'][] = ['handle' => $handle, 'field' => $config];

        return $this->set('tabs', $tabs);
    }

    public function removeField(string $handle): static
    {
        $tabs = $this->tabs();

        foreach ($tabs as $t => $tab) {
            foreach ((array) ($tab['sections'] ?? []) as $s => $section) {
                $tabs[$t]['sections'][$s]['fields'] = array_values(array_filter(
                    (array) ($section['fields'] ?? []),
                    fn ($field) => ($field['handle'] ?? null) !== $handle,
                ));
            }
        }

        return $this->set('tabs', $tabs);
    }
}
