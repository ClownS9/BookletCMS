<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\YourBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Text follows the chosen fonts: the admin's (Make it yours), the site's
 * (the cover's title and label fonts). Only code, keys, addresses and file
 * names keep the typewriter font, and a few previews show other fonts on
 * purpose (a search result, a share card, a browser tab, font samples).
 */
class FontsFollowTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_style_names_a_font_except_the_previews_that_must(): void
    {
        $allowed = ['.serp', '.share-fb', '.share-wa', '.brandkit__tab', '.brandkit__app', '.cover-preset__title'];
        foreach (['admin.css', 'site.css', 'builder.css', 'builder-edit.css', 'print.css'] as $file) {
            $css = (string) file_get_contents(public_path('booklet/'.$file));
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
            $named = [];
            foreach ($rules as [, $selector, $body]) {
                $selector = trim(preg_replace('#/\*.*?\*/#s', '', $selector));
                if (preg_match('/font(-family)?\s*:\s*[^;]*["\'](Instrument Sans|Newsreader|JetBrains Mono|Lora|IBM Plex)/', $body)
                    && ! preg_match('/var\(--(sans|serif|mono|label|ui-sans|ui-serif|ui-label)/', $body)
                    && ! str_contains($selector, ':root') && ! str_contains($selector, '@font-face')
                    && $selector !== 'code' // code is meant to be in the typewriter font
                    && ! collect($allowed)->contains(fn ($a) => str_contains($selector, $a))) {
                    $named[] = $selector;
                }
            }
            $this->assertSame([], $named, $file.' names a font instead of following the chosen ones');
        }
    }

    public function test_labels_follow_each_font_choice(): void
    {
        $css = (string) file_get_contents(public_path('booklet/admin.css'));
        $this->assertStringContainsString(':root { --label: var(--mono); }', $css);
        $this->assertStringContainsString('body.layout-dashboard { --label: var(--sans); }', $css);
        foreach (['classic', 'plain', 'friendly', 'easy'] as $font) {
            $this->assertMatchesRegularExpression('/:root:has\(> body\.font-'.$font.'\) \{[^}]*--label: var\(--sans\)/', $css, $font);
        }
        $this->assertMatchesRegularExpression("/body\.font-plex\) \{[^}]*--label: 'IBM Plex Mono'/", $css);
        $this->assertStringContainsString('IBM+Plex+Mono', YourBook::FONTS['plex'][4]);
        $this->assertStringContainsString('.eyebrow { font-family: var(--label, var(--mono))', (string) file_get_contents(public_path('booklet/site.css')));
    }

    public function test_the_page_builders_panel_wears_the_editors_fonts(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        YourBook::save($user, ['font' => 'plex']);
        $style = YourBook::uiFontStyle($user->fresh());
        $this->assertStringContainsString("--ui-sans: 'IBM Plex Sans'", $style);
        $this->assertStringContainsString("--ui-label: 'IBM Plex Mono'", $style);

        $css = (string) file_get_contents(public_path('booklet/builder-edit.css'));
        $this->assertStringContainsString('font: 14px/1.45 var(--ui-sans,', $css);
        $this->assertStringContainsString("(host.dataset.fonts || '')", (string) file_get_contents(public_path('booklet/builder-edit.js')));
    }
}
