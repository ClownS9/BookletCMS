<?php

namespace Tests\Unit;

use App\Booklet\Support\WordDiff;
use PHPUnit\Framework\TestCase;

class WordDiffTest extends TestCase
{
    public function test_it_marks_added_and_removed_words(): void
    {
        $segments = WordDiff::compare('the quick brown fox jumps', 'the slow brown fox leaps high');

        $this->assertSame([
            ['same', 'the '],
            ['removed', 'quick '],
            ['added', 'slow '],
            ['same', 'brown fox '],
            ['removed', 'jumps'],
            ['added', 'leaps high'],
        ], $segments);

        $this->assertSame(['added' => 3, 'removed' => 2], WordDiff::stats($segments));
    }

    public function test_joining_the_kept_and_added_parts_gives_the_new_text(): void
    {
        $old = "A first paragraph.\n\nA second one with words.";
        $new = "A first paragraph, edited.\n\nA second one with more words.";

        $rebuilt = collect(WordDiff::compare($old, $new))
            ->reject(fn ($segment) => $segment[0] === 'removed')
            ->map(fn ($segment) => $segment[1])
            ->implode('');

        $this->assertSame($new, $rebuilt);
    }

    public function test_identical_and_empty_texts(): void
    {
        $this->assertSame([['same', 'same text']], WordDiff::compare('same text', 'same text'));
        $this->assertSame([['added', 'hello there']], WordDiff::compare('', 'hello there'));
        $this->assertSame([], WordDiff::compare('', ''));
    }
}
