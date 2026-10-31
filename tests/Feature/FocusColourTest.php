<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Where the keyboard is shows in the theme's colour: --focus in the admin
 * (the chapter's colour in the Book layout, the theme's accent in the
 * Dashboard layout), the site's accent on the public site.
 */
class FocusColourTest extends TestCase
{
    public function test_no_focus_style_names_a_colour_of_its_own(): void
    {
        foreach (['admin.css', 'site.css'] as $file) {
            $css = (string) file_get_contents(public_path('booklet/'.$file));
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
            $fixed = [];
            foreach ($rules as [, $selector, $body]) {
                if (str_contains($selector, ':focus') && preg_match('/(outline|box-shadow)[^;]*var\(--(sky|ink|sun)\)/', $body)
                    && ! preg_match('/color-mix\([^)]*var\(--(accent|focus|d-accent)\)/', $body)) {
                    $fixed[] = trim(substr($selector, -80));
                }
            }
            $this->assertSame([], $fixed, $file.': focus styles should use the theme colour');
        }
    }

    public function test_the_dashboard_focus_colour_is_its_accent(): void
    {
        $css = (string) file_get_contents(public_path('booklet/admin.css'));
        $this->assertStringContainsString('body.layout-dashboard { --focus: var(--d-accent) !important; }', $css);
        $this->assertStringContainsString(':focus-visible { outline: 2px solid color-mix(in srgb, var(--accent) 72%, var(--ink));', (string) file_get_contents(public_path('booklet/site.css')));
    }
}
