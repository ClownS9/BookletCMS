<?php

namespace Database\Seeders;

use App\Booklet\Facades\Booklet;
use App\Models\Comment;
use Illuminate\Database\Seeder;

/**
 * A starting book: Posts and Pages with their blueprints, Categories and Tags,
 * header and footer menus, site globals, a Media container and a contact form.
 *
 * Goes through the Booklet repositories, so it writes to whichever drivers are
 * configured: rows in the database, or files in content/ and resources/.
 *
 * Safe to run again: it only fills in what's missing. Pages and posts are found
 * by their slug, so a second run (or First setup after `db:seed`, or a seed on
 * top of content files that `migrate:fresh` doesn't touch) never makes
 * "Home" and "Home 2", and never overwrites the site's name or menus.
 */
class BookletStarterSeeder extends Seeder
{
    public function run(): void
    {
        $this->collections();
        $this->blueprints();
        $this->taxonomies();
        $pages = $this->pages();
        $this->posts();
        $this->navigation($pages);
        $this->globals();
        $this->media();
        $this->forms();
    }

    protected function collections(): void
    {
        Booklet::collections()->make(['handle' => 'posts', 'title' => 'Posts'], [
            'route' => '/blog/{slug}',
            'dated' => true,
            'sort_dir' => 'desc',
            'taxonomies' => ['categories', 'tags'],
            'sticker' => '#F3C24F',
        ])->save();

        Booklet::collections()->make(['handle' => 'pages', 'title' => 'Pages'], [
            'route' => '{parent_uri}/{slug}',
            'structured' => true,
            'sticker' => '#86CDB9',
        ])->save();
    }

    protected function blueprints(): void
    {
        Booklet::blueprints()->make(['namespace' => 'collections.posts', 'handle' => 'post'], [
            'title' => 'Post',
            'tabs' => [
                'main' => ['display' => 'Main', 'sections' => [['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title', 'validate' => ['required']]],
                    ['handle' => 'content', 'field' => ['type' => 'markdown', 'display' => 'Body']],
                ]]]],
                'sidebar' => ['display' => 'Margin notes', 'sections' => [['fields' => [
                    ['handle' => 'excerpt', 'field' => ['type' => 'textarea', 'display' => 'Standfirst', 'instructions' => 'A line under the title that sets up the story.']],
                    ['handle' => 'cover', 'field' => ['type' => 'assets', 'container' => 'media', 'max_files' => 1, 'display' => 'Cover image']],
                    ['handle' => 'cover_caption', 'field' => ['type' => 'text', 'display' => 'Cover caption', 'instructions' => 'Caption and credit, shown under the picture.']],
                    ['handle' => 'categories', 'field' => ['type' => 'terms', 'taxonomies' => ['categories'], 'max_items' => 1, 'display' => 'Category']],
                    ['handle' => 'tags', 'field' => ['type' => 'terms', 'taxonomies' => ['tags'], 'display' => 'Tags']],
                    ['import' => 'seo'],
                ]]]],
            ],
        ])->save();

        Booklet::blueprints()->make(['namespace' => 'collections.pages', 'handle' => 'page'], [
            'title' => 'Page',
            'tabs' => [
                'main' => ['display' => 'Main', 'sections' => [['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title', 'validate' => ['required']]],
                    ['handle' => 'content', 'field' => ['type' => 'markdown', 'display' => 'Body']],
                    ['handle' => 'blocks', 'field' => ['type' => 'blocks', 'display' => 'Cut-outs', 'instructions' => 'Quotes, pictures, buttons and more, after the body.']],
                ]]]],
                'sidebar' => ['display' => 'Margin notes', 'sections' => [['fields' => [
                    ['handle' => 'template', 'field' => ['type' => 'select', 'display' => 'Template', 'default' => 'default',
                        'options' => ['default' => 'Default', 'full_width' => 'Full width', 'landing' => 'Landing']]],
                    ['handle' => 'excerpt', 'field' => ['type' => 'textarea', 'display' => 'Standfirst', 'instructions' => 'One line on what this page is about.']],
                    ['handle' => 'cover', 'field' => ['type' => 'assets', 'container' => 'media', 'max_files' => 1, 'display' => 'Header image']],
                    ['handle' => 'show_people', 'field' => ['type' => 'toggle', 'display' => 'Show the people behind it', 'instructions' => 'Everyone in the book, with their bylines.']],
                    ['import' => 'seo'],
                ]]]],
            ],
        ])->save();

        Booklet::fieldsets()->make(['handle' => 'seo'], [
            'title' => 'SEO',
            'fields' => [
                ['handle' => 'meta_title', 'field' => ['type' => 'text', 'display' => 'Meta title']],
                ['handle' => 'meta_description', 'field' => ['type' => 'textarea', 'display' => 'Meta description']],
                ['handle' => 'share_image', 'field' => ['type' => 'assets', 'container' => 'media', 'max_files' => 1, 'display' => 'Share image']],
            ],
        ])->save();
    }

    protected function taxonomies(): void
    {
        Booklet::taxonomies()->make(['handle' => 'categories', 'title' => 'Categories'], ['route' => '/blog/category/{slug}'])->save();
        Booklet::taxonomies()->make(['handle' => 'tags', 'title' => 'Tags'], ['route' => '/blog/tag/{slug}'])->save();

        foreach (['categories' => ['News', 'Notes'], 'tags' => ['Welcome']] as $taxonomy => $titles) {
            foreach ($titles as $title) {
                if (! Booklet::terms()->findBySlug($taxonomy, \Illuminate\Support\Str::slug($title))) {
                    Booklet::terms()->make(['taxonomy' => $taxonomy], ['title' => $title])->save();
                }
            }
        }
    }

    /**
     * @return array<string, string> page slug => entry id
     */
    protected function pages(): array
    {
        $ids = [];

        foreach ([
            'home' => ['Home', "Welcome to your new book.\n\nEdit this page from **Pages** in the admin."],
            'about' => ['About', 'A few lines about who writes here.'],
            'contact' => ['Contact', 'How to get in touch.'],
        ] as $slug => [$title, $content]) {
            // Already there? Use it, rather than making "home-2".
            $ids[$slug] = Booklet::entries()->findBySlug('pages', $slug)?->id()
                ?? Booklet::entries()->make(
                    ['collection' => 'pages', 'slug' => $slug, 'blueprint' => 'page'],
                    ['title' => $title, 'content' => $content, 'template' => 'default'],
                )->save()->id();
        }

        // Add only the pages the tree doesn't have yet; keep any order already chosen.
        $tree = Booklet::trees()->findOrMake('collection', 'pages');
        $missing = array_diff(array_values($ids), $tree->entryIds());
        if ($missing !== []) {
            $tree->setTree([...$tree->tree(), ...array_map(fn ($id) => ['entry' => $id], array_values($missing))])->save();
        }

        return $ids;
    }

    protected function posts(): void
    {
        if (Booklet::entries()->findBySlug('posts', 'hello-from-booklet')) {
            return;
        }

        $post = Booklet::entries()->make(['collection' => 'posts', 'blueprint' => 'post'], [
            'title' => 'Hello from Booklet',
            'content' => "This is your first post.\n\nWrite, tag it, and publish it from the **Posts** chapter.",
            'categories' => ['news'],
            'tags' => ['welcome'],
            'no_email' => true, // sample text: never sent to subscribers
        ])->save();

        // One note waiting in Comments, so that chapter has something to show.
        Comment::create([
            'entry_id' => $post->id(),
            'author_name' => 'A first reader',
            'author_email' => 'reader@example.com',
            'body' => 'Lovely to see the new site. Looking forward to the next post!',
            'status' => 'waiting',
        ]);
    }

    /**
     * @param  array<string, string>  $pages
     */
    protected function navigation(array $pages): void
    {
        // Menus someone has already arranged are left as they are.
        if (Booklet::trees()->navigation('header')) {
            return;
        }

        Booklet::navigations()->make(['handle' => 'header', 'title' => 'Header menu'], ['collections' => ['pages', 'posts'], 'max_depth' => 2])->save();
        Booklet::navigations()->make(['handle' => 'footer', 'title' => 'Footer menu'], ['collections' => ['pages'], 'max_depth' => 1])->save();

        Booklet::trees()->findOrMake('navigation', 'header')->setTree([
            ['entry' => $pages['home']],
            ['entry' => $pages['about']],
            ['url' => '/blog', 'title' => 'Blog'],
            ['entry' => $pages['contact']],
        ])->save();

        Booklet::trees()->findOrMake('navigation', 'footer')->setTree([
            ['entry' => $pages['contact']],
        ])->save();
    }

    protected function globals(): void
    {
        // Never overwrite the site's name, tagline or accent once set.
        if (Booklet::globals('site')) {
            return;
        }

        Booklet::globalSets()->make(['handle' => 'site', 'title' => 'Site'])->save();

        Booklet::globalVariables()->make(['handle' => 'site', 'locale' => config('booklet.default_site')], [
            'site_name' => config('app.name', 'Booklet'),
            'tagline' => null,
            'accent' => '#F4876A',
        ])->save();
    }

    protected function media(): void
    {
        Booklet::assetContainers()->make(['handle' => 'media', 'title' => 'Media', 'disk' => 'public'], [
            'allow_uploads' => true,
            'folders' => ['covers', 'blog', 'logos', 'documents'],
        ])->save();
    }

    protected function forms(): void
    {
        Booklet::forms()->make(['handle' => 'contact', 'title' => 'Contact'], [
            'honeypot' => 'website',
            'email' => [['to' => 'hello@example.com', 'subject' => 'New message from the site']],
        ])->save();

        Booklet::blueprints()->make(['namespace' => 'forms', 'handle' => 'contact'], [
            'title' => 'Contact',
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text', 'validate' => ['required']]],
                ['handle' => 'email', 'field' => ['type' => 'text', 'input_type' => 'email', 'validate' => ['required', 'email']]],
                ['handle' => 'message', 'field' => ['type' => 'textarea', 'validate' => ['required']]],
            ]]]]],
        ])->save();
    }
}
