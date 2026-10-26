<?php

/*
 * The page builder's sections: what each one holds, how it starts, and the
 * looks every section can wear. App\PageBuilder reads this; the views under
 * resources/views/builder/sections/ draw them. Sections marked 'blog' are only for the blog
 * page ('required': The posts it must have; 'once': one at most).
 *
 * Field kinds:
 *   text     one line, typed straight onto the page
 *   words    a few lines (paragraphs split by an empty line), typed on the page
 *   link     a button: its words typed on the page, its address set on the left
 *   picture  one picture from Media
 *   pictures several pictures from Media
 *   select   one of a few choices
 *   url      an address (a video's, say)
 *   number   a small number
 *   toggle   on or off
 *   items    a list of things (cards, questions, plans…), each with its own fields
 */

return [
    'sections' => [
        'heading' => [
            'name' => 'Heading',
            'about' => 'A heading with small words above it and a line under it. Quieter than a hero.',
            'group' => 'Openings and endings',
            'fields' => [
                'eyebrow' => ['type' => 'text', 'label' => 'Small words above'],
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'lede' => ['type' => 'words', 'label' => 'A line or two'],
            ],
            'start' => ['eyebrow' => '', 'title' => 'A heading', 'lede' => ''],
            'style' => ['space' => 'snug'],
        ],

        'hero' => [
            'name' => 'Hero',
            'about' => 'A big opening: a heading, a line or two, buttons and a picture.',
            'group' => 'Openings and endings',
            'fields' => [
                'eyebrow' => ['type' => 'text', 'label' => 'Small words above'],
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'lede' => ['type' => 'words', 'label' => 'A line or two'],
                'button' => ['type' => 'link', 'label' => 'Button'],
                'button2' => ['type' => 'link', 'label' => 'Second button'],
                'picture' => ['type' => 'picture', 'label' => 'Picture'],
                'layout' => ['type' => 'select', 'label' => 'Where the picture goes', 'options' => ['side' => 'Beside the words', 'below' => 'Under the words', 'none' => 'No picture']],
            ],
            'start' => [
                'eyebrow' => 'Welcome',
                'title' => 'Say what you do, in a line',
                'lede' => 'One or two sentences on who it’s for and why it matters. Click any words on the page to change them.',
                'button' => ['label' => 'Get started', 'url' => '#'],
                'button2' => ['label' => '', 'url' => ''],
                'layout' => 'side',
            ],
            'style' => ['align' => 'left', 'space' => 'roomy'],
        ],

        'text-picture' => [
            'name' => 'Words and a picture',
            'about' => 'A heading and a few paragraphs, with a picture to one side.',
            'group' => 'Openings and endings',
            'fields' => [
                'eyebrow' => ['type' => 'text', 'label' => 'Small words above'],
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'body' => ['type' => 'words', 'label' => 'Words', 'max' => 6000],
                'button' => ['type' => 'link', 'label' => 'Button'],
                'picture' => ['type' => 'picture', 'label' => 'Picture'],
                'side' => ['type' => 'select', 'label' => 'Picture on the', 'options' => ['right' => 'Right', 'left' => 'Left']],
            ],
            'start' => [
                'title' => 'A heading for this part',
                'body' => "A paragraph or two about it. Keep it to what a reader needs.\n\nAn empty line starts a new paragraph.",
                'button' => ['label' => '', 'url' => ''],
                'side' => 'right',
            ],
        ],

        'cta' => [
            'name' => 'Call to action',
            'about' => 'One clear thing to do next, with a button.',
            'group' => 'Openings and endings',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'words' => ['type' => 'words', 'label' => 'Words'],
                'button' => ['type' => 'link', 'label' => 'Button'],
                'button2' => ['type' => 'link', 'label' => 'Second button'],
            ],
            'start' => [
                'title' => 'Ready when you are',
                'words' => 'One line on what happens when they click.',
                'button' => ['label' => 'Say hello', 'url' => '#'],
                'button2' => ['label' => '', 'url' => ''],
            ],
            'style' => ['background' => 'accent', 'align' => 'center'],
        ],

        'banner' => [
            'name' => 'Banner',
            'about' => 'Big words over a picture, as wide as the page.',
            'group' => 'Openings and endings',
            'fields' => [
                'eyebrow' => ['type' => 'text', 'label' => 'Small words above'],
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'words' => ['type' => 'words', 'label' => 'A line or two', 'max' => 600],
                'button' => ['type' => 'link', 'label' => 'Button'],
                'picture' => ['type' => 'picture', 'label' => 'Picture'],
                'height' => ['type' => 'select', 'label' => 'How tall', 'options' => ['short' => 'Short', 'tall' => 'Tall']],
            ],
            'start' => [
                'eyebrow' => '',
                'title' => 'Something worth stopping for',
                'words' => 'A line or two, over the picture.',
                'button' => ['label' => 'Find out more', 'url' => '#'],
                'picture' => '',
                'height' => 'short',
            ],
            'style' => ['align' => 'center', 'width' => 'wide'],
        ],

        'notice' => [
            'name' => 'Announcement',
            'about' => 'One short line with a sticker and a link, for news that matters now.',
            'group' => 'Openings and endings',
            'fields' => [
                'sticker' => ['type' => 'text', 'label' => 'Sticker', 'max' => 24],
                'words' => ['type' => 'text', 'label' => 'The news', 'max' => 200],
                'link' => ['type' => 'link', 'label' => 'Link'],
            ],
            'start' => ['sticker' => 'New', 'words' => 'Something people should know about, in a line.', 'link' => ['label' => 'Read more', 'url' => '#']],
            'style' => ['space' => 'snug', 'background' => 'soft', 'align' => 'center'],
        ],

        'divider' => [
            'name' => 'Divider',
            'about' => 'A quiet break between sections: a line, a few dots, or just space.',
            'group' => 'Openings and endings',
            'fields' => [
                'style' => ['type' => 'select', 'label' => 'Shown as', 'options' => ['line' => 'A line', 'short' => 'A short line', 'dots' => 'Three dots', 'space' => 'Just space']],
            ],
            'start' => ['style' => 'line'],
            'style' => ['space' => 'snug'],
        ],

        'cards' => [
            'name' => 'Cards',
            'about' => 'A row of cards, each with a picture, a heading, a few words and a link.',
            'group' => 'Lists',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'intro' => ['type' => 'words', 'label' => 'A line under it'],
                'across' => ['type' => 'select', 'label' => 'Across', 'options' => ['2' => 'Two', '3' => 'Three', '4' => 'Four']],
                'items' => ['type' => 'items', 'label' => 'Cards', 'one' => 'card', 'max_items' => 12, 'fields' => [
                    'picture' => ['type' => 'picture', 'label' => 'Picture'],
                    'title' => ['type' => 'text', 'label' => 'Heading'],
                    'words' => ['type' => 'words', 'label' => 'Words'],
                    'url' => ['type' => 'url', 'label' => 'Goes to (optional)'],
                ]],
            ],
            'start' => [
                'title' => 'What you’ll find',
                'intro' => '',
                'across' => '3',
                'items' => [
                    ['title' => 'First thing', 'words' => 'A sentence about it.', 'url' => ''],
                    ['title' => 'Second thing', 'words' => 'A sentence about it.', 'url' => ''],
                    ['title' => 'Third thing', 'words' => 'A sentence about it.', 'url' => ''],
                ],
            ],
        ],

        'columns' => [
            'name' => 'Columns',
            'about' => 'Short points side by side, each with a mark, a heading and a few words.',
            'group' => 'Lists',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'intro' => ['type' => 'words', 'label' => 'A line under it'],
                'across' => ['type' => 'select', 'label' => 'Across', 'options' => ['2' => 'Two', '3' => 'Three', '4' => 'Four']],
                'items' => ['type' => 'items', 'label' => 'Columns', 'one' => 'column', 'max_items' => 12, 'fields' => [
                    'mark' => ['type' => 'text', 'label' => 'Mark (an emoji or a number)', 'max' => 8],
                    'title' => ['type' => 'text', 'label' => 'Heading'],
                    'words' => ['type' => 'words', 'label' => 'Words'],
                ]],
            ],
            'start' => [
                'title' => 'Why people choose it',
                'intro' => '',
                'across' => '3',
                'items' => [
                    ['mark' => '1', 'title' => 'Quick', 'words' => 'A sentence on this point.'],
                    ['mark' => '2', 'title' => 'Friendly', 'words' => 'A sentence on this point.'],
                    ['mark' => '3', 'title' => 'Yours', 'words' => 'A sentence on this point.'],
                ],
            ],
        ],

        'faq' => [
            'name' => 'Questions',
            'about' => 'Questions people ask, each opening to its answer.',
            'group' => 'Lists',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'intro' => ['type' => 'words', 'label' => 'A line under it'],
                'items' => ['type' => 'items', 'label' => 'Questions', 'one' => 'question', 'max_items' => 30, 'fields' => [
                    'question' => ['type' => 'text', 'label' => 'Question', 'max' => 300],
                    'answer' => ['type' => 'words', 'label' => 'Answer'],
                ]],
            ],
            'start' => [
                'title' => 'Questions',
                'intro' => '',
                'items' => [
                    ['question' => 'A question people ask?', 'answer' => 'The answer, in a sentence or two.'],
                    ['question' => 'Another one?', 'answer' => 'Its answer.'],
                ],
            ],
            'style' => ['width' => 'narrow'],
        ],

        'free' => [
            'name' => 'Columns of your own',
            'about' => 'Two to four columns, each with any of a picture, a heading, words and a button.',
            'group' => 'Lists',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'intro' => ['type' => 'words', 'label' => 'A line under it'],
                'widths' => ['type' => 'select', 'label' => 'Widths (with two columns)', 'options' => ['even' => 'The same', 'left' => 'Wider on the left', 'right' => 'Wider on the right']],
                'items' => ['type' => 'items', 'label' => 'Columns', 'one' => 'column', 'max_items' => 4, 'fields' => [
                    'picture' => ['type' => 'picture', 'label' => 'Picture (optional)'],
                    'title' => ['type' => 'text', 'label' => 'Heading (optional)'],
                    'words' => ['type' => 'words', 'label' => 'Words (optional)', 'max' => 3000],
                    'button' => ['type' => 'link', 'label' => 'Button (optional)'],
                ]],
            ],
            'start' => [
                'title' => '',
                'intro' => '',
                'widths' => 'even',
                'items' => [
                    ['picture' => '', 'title' => 'On the left', 'words' => 'Anything you like here: words, a picture, a button, or all three.', 'button' => ['label' => '', 'url' => '']],
                    ['picture' => '', 'title' => 'On the right', 'words' => 'Each column has the same choices. Leave out what you don’t need.', 'button' => ['label' => '', 'url' => '']],
                ],
            ],
        ],

        'stats' => [
            'name' => 'Numbers',
            'about' => 'A few big figures, each with what it counts.',
            'group' => 'Lists',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'intro' => ['type' => 'words', 'label' => 'A line under it'],
                'items' => ['type' => 'items', 'label' => 'Numbers', 'one' => 'number', 'max_items' => 8, 'fields' => [
                    'number' => ['type' => 'text', 'label' => 'The number (12, 40+, 98%…)', 'max' => 16],
                    'label' => ['type' => 'text', 'label' => 'What it counts', 'max' => 80],
                    'words' => ['type' => 'words', 'label' => 'A few words more (optional)', 'max' => 300],
                ]],
            ],
            'start' => [
                'title' => '',
                'intro' => '',
                'items' => [
                    ['number' => '12', 'label' => 'years at it', 'words' => ''],
                    ['number' => '40+', 'label' => 'things we make', 'words' => ''],
                    ['number' => '7', 'label' => 'days a week', 'words' => ''],
                ],
            ],
            'style' => ['align' => 'center'],
        ],

        'steps' => [
            'name' => 'Steps',
            'about' => 'How it works, in numbered steps.',
            'group' => 'Lists',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'intro' => ['type' => 'words', 'label' => 'A line under it'],
                'items' => ['type' => 'items', 'label' => 'Steps', 'one' => 'step', 'max_items' => 8, 'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Heading'],
                    'words' => ['type' => 'words', 'label' => 'Words'],
                ]],
            ],
            'start' => [
                'title' => 'How it works',
                'intro' => '',
                'items' => [
                    ['title' => 'First', 'words' => 'What happens first, in a sentence.'],
                    ['title' => 'Then', 'words' => 'What comes next.'],
                    ['title' => 'And then', 'words' => 'How it ends, happily.'],
                ],
            ],
        ],

        'gallery' => [
            'name' => 'Pictures',
            'about' => 'A grid of pictures from Media.',
            'group' => 'Pictures and voices',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'pictures' => ['type' => 'pictures', 'label' => 'Pictures', 'max_items' => 40],
                'across' => ['type' => 'select', 'label' => 'Across', 'options' => ['2' => 'Two', '3' => 'Three', '4' => 'Four']],
                'captions' => ['type' => 'toggle', 'label' => 'Show each picture’s description'],
            ],
            'start' => ['title' => '', 'pictures' => [], 'across' => '3', 'captions' => false],
        ],

        'quotes' => [
            'name' => 'Quotes',
            'about' => 'Kind words from real people, with their names.',
            'group' => 'Pictures and voices',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'items' => ['type' => 'items', 'label' => 'Quotes', 'one' => 'quote', 'max_items' => 12, 'fields' => [
                    'words' => ['type' => 'words', 'label' => 'What they said'],
                    'name' => ['type' => 'text', 'label' => 'Who said it'],
                    'role' => ['type' => 'text', 'label' => 'Who they are'],
                    'picture' => ['type' => 'picture', 'label' => 'Their picture'],
                ]],
            ],
            'start' => [
                'title' => '',
                'items' => [
                    ['words' => 'Something a real person said about it. Use their own words, with their say-so.', 'name' => 'Their name', 'role' => 'Who they are'],
                ],
            ],
            'style' => ['background' => 'soft'],
        ],

        'team' => [
            'name' => 'People',
            'about' => 'The people behind it: a picture, a name, what they do.',
            'group' => 'Pictures and voices',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'intro' => ['type' => 'words', 'label' => 'A line under it'],
                'across' => ['type' => 'select', 'label' => 'Across', 'options' => ['2' => 'Two', '3' => 'Three', '4' => 'Four']],
                'items' => ['type' => 'items', 'label' => 'People', 'one' => 'person', 'max_items' => 24, 'fields' => [
                    'picture' => ['type' => 'picture', 'label' => 'Their picture'],
                    'name' => ['type' => 'text', 'label' => 'Name'],
                    'role' => ['type' => 'text', 'label' => 'What they do'],
                    'words' => ['type' => 'words', 'label' => 'A few words about them', 'max' => 600],
                ]],
            ],
            'start' => [
                'title' => 'Who we are',
                'intro' => '',
                'across' => '3',
                'items' => [
                    ['name' => 'Their name', 'role' => 'What they do', 'words' => ''],
                    ['name' => 'Their name', 'role' => 'What they do', 'words' => ''],
                    ['name' => 'Their name', 'role' => 'What they do', 'words' => ''],
                ],
            ],
            'style' => ['align' => 'center'],
        ],

        'logos' => [
            'name' => 'Logos',
            'about' => 'A row of logos from Media: who you work with, where you’ve been seen.',
            'group' => 'Pictures and voices',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'pictures' => ['type' => 'pictures', 'label' => 'Logos', 'max_items' => 24],
                'soft' => ['type' => 'toggle', 'label' => 'Quiet: grey until pointed at'],
            ],
            'start' => ['title' => 'As seen in', 'pictures' => [], 'soft' => true],
            'style' => ['align' => 'center', 'space' => 'snug'],
        ],

        'video' => [
            'name' => 'Video',
            'about' => 'A YouTube or Vimeo video, with a heading and a caption.',
            'group' => 'Pictures and voices',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'url' => ['type' => 'url', 'label' => 'The video’s address (YouTube or Vimeo)', 'video' => true],
                'caption' => ['type' => 'text', 'label' => 'Caption', 'max' => 300],
            ],
            'start' => ['title' => '', 'url' => '', 'caption' => ''],
        ],

        'pricing' => [
            'name' => 'Prices',
            'about' => 'Plans side by side: a name, a price, what’s in it and a button.',
            'group' => 'Doing business',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'intro' => ['type' => 'words', 'label' => 'A line under it'],
                'items' => ['type' => 'items', 'label' => 'Plans', 'one' => 'plan', 'max_items' => 6, 'fields' => [
                    'name' => ['type' => 'text', 'label' => 'Name'],
                    'price' => ['type' => 'text', 'label' => 'Price', 'max' => 40],
                    'per' => ['type' => 'text', 'label' => 'Per (a month, once…)', 'max' => 40],
                    'words' => ['type' => 'words', 'label' => 'What’s in it (a line each)'],
                    'button' => ['type' => 'link', 'label' => 'Button'],
                    'featured' => ['type' => 'toggle', 'label' => 'Stand out (the one most people pick)'],
                ]],
            ],
            'start' => [
                'title' => 'Prices',
                'intro' => '',
                'items' => [
                    ['name' => 'Starter', 'price' => '$9', 'per' => 'a month', 'words' => "One thing\nAnother thing", 'button' => ['label' => 'Choose', 'url' => '#'], 'featured' => false],
                    ['name' => 'Plus', 'price' => '$19', 'per' => 'a month', 'words' => "Everything in Starter\nOne more thing", 'button' => ['label' => 'Choose', 'url' => '#'], 'featured' => true],
                ],
            ],
            'style' => ['align' => 'center'],
        ],

        'menu' => [
            'name' => 'Menu',
            'about' => 'What you sell, each with a price and a few words, in groups if you like.',
            'group' => 'Doing business',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'intro' => ['type' => 'words', 'label' => 'A line under it'],
                'across' => ['type' => 'select', 'label' => 'Across', 'options' => ['1' => 'One', '2' => 'Two']],
                'items' => ['type' => 'items', 'label' => 'Things', 'one' => 'thing', 'max_items' => 80, 'fields' => [
                    'group' => ['type' => 'text', 'label' => 'Starts a group: its heading (optional)', 'max' => 80],
                    'name' => ['type' => 'text', 'label' => 'Name', 'max' => 120],
                    'price' => ['type' => 'text', 'label' => 'Price', 'max' => 30],
                    'tag' => ['type' => 'text', 'label' => 'Sticker (New, Vegan…)', 'max' => 24],
                    'words' => ['type' => 'words', 'label' => 'A few words', 'max' => 300],
                ]],
            ],
            'start' => [
                'title' => 'The menu',
                'intro' => '',
                'across' => '2',
                'items' => [
                    ['group' => 'A group', 'name' => 'Something good', 'price' => '$4', 'tag' => 'New', 'words' => 'What’s in it, in a line.'],
                    ['group' => '', 'name' => 'Something else', 'price' => '$5', 'tag' => '', 'words' => 'What’s in it, in a line.'],
                    ['group' => 'Another group', 'name' => 'One more', 'price' => '$3', 'tag' => '', 'words' => ''],
                    ['group' => '', 'name' => 'And another', 'price' => '$3', 'tag' => '', 'words' => ''],
                ],
            ],
        ],

        'hours' => [
            'name' => 'Opening hours',
            'about' => 'When you’re open and where to find you, with a button for directions.',
            'group' => 'Doing business',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'items' => ['type' => 'items', 'label' => 'Times', 'one' => 'time', 'max_items' => 14, 'fields' => [
                    'days' => ['type' => 'text', 'label' => 'Days', 'max' => 60],
                    'time' => ['type' => 'text', 'label' => 'Open', 'max' => 60],
                ]],
                'note' => ['type' => 'words', 'label' => 'A note under the times (holidays…)', 'max' => 400],
                'address' => ['type' => 'words', 'label' => 'Where (a line each)', 'max' => 400],
                'button' => ['type' => 'link', 'label' => 'Button (a map link, say)'],
            ],
            'start' => [
                'title' => 'Come and see us',
                'items' => [
                    ['days' => 'Monday to Friday', 'time' => '8am – 6pm'],
                    ['days' => 'Saturday', 'time' => '9am – 4pm'],
                    ['days' => 'Sunday', 'time' => 'Closed'],
                ],
                'note' => '',
                'address' => "Your street\nYour town",
                'button' => ['label' => 'Get directions', 'url' => ''],
            ],
        ],

        'posts' => [
            'name' => 'Latest posts',
            'about' => 'The newest posts from the blog, as cards. They keep themselves up to date.',
            'group' => 'Doing business',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'count' => ['type' => 'select', 'label' => 'How many', 'options' => ['3' => 'Three', '6' => 'Six', '9' => 'Nine']],
                'category' => ['type' => 'select', 'label' => 'From', 'options' => 'categories'],
                'more' => ['type' => 'text', 'label' => 'Link to the blog', 'max' => 60],
            ],
            'start' => ['title' => 'From the blog', 'count' => '3', 'category' => '', 'more' => 'All posts'],
        ],

        'contact' => [
            'name' => 'Contact',
            'about' => 'The contact form (messages arrive in the Inbox), with a few words beside it.',
            'group' => 'Doing business',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'words' => ['type' => 'words', 'label' => 'Words beside the form'],
                'details' => ['type' => 'words', 'label' => 'Other ways to reach you (a line each)'],
            ],
            'start' => ['title' => 'Say hello', 'words' => 'Send a message and we’ll write back soon.', 'details' => ''],
            'style' => ['width' => 'narrow'],
        ],

        'form' => [
            'name' => 'A form',
            'about' => 'One of your own forms (a sign-up, an RSVP, an order), made in Comments › Forms. Answers arrive in the Inbox.',
            'group' => 'Doing business',
            'fields' => [
                'form' => ['type' => 'select', 'label' => 'Which form', 'options' => 'forms'],
                'title' => ['type' => 'text', 'label' => 'Heading', 'max' => 160],
                'words' => ['type' => 'words', 'label' => 'Words beside it'],
            ],
            'start' => ['form' => '', 'title' => 'Sign up', 'words' => 'Fill this in and we’ll be in touch.'],
            'style' => ['width' => 'narrow'],
        ],

        'subscribe' => [
            'name' => 'New posts by email',
            'about' => 'The box for signing up to new posts (when emails are switched on).',
            'group' => 'Doing business',
            'fields' => [],
            'start' => [],
            'style' => ['space' => 'snug'],
        ],

        // The blog page's own ----------------------------------------------------------------
        'blog-featured' => [
            'name' => 'Featured story',
            'about' => 'The newest post, large, on the first page of the blog.',
            'group' => 'The blog',
            'blog' => true,
            'once' => true,
            'fields' => ['label' => ['type' => 'text', 'label' => 'Its sticker', 'max' => 30]],
            'start' => ['label' => 'Featured'],
            'style' => ['space' => 'snug'],
        ],
        'blog-categories' => [
            'name' => 'Categories',
            'about' => 'Chips that show one category at a time.',
            'group' => 'The blog',
            'blog' => true,
            'once' => true,
            'fields' => ['all' => ['type' => 'text', 'label' => 'The first chip', 'max' => 30]],
            'start' => ['all' => 'All'],
            'style' => ['space' => 'snug'],
        ],
        'blog-posts' => [
            'name' => 'The posts',
            'about' => 'Every post, a page at a time, with the sidebar beside them.',
            'group' => 'The blog',
            'blog' => true,
            'required' => true, // the blog page is its posts: they move, but don't go
            'fields' => [
                'style' => ['type' => 'select', 'label' => 'Shown as', 'options' => ['grid' => 'Cards, side by side', 'wide' => 'One per row, large', 'list' => 'A list (no pictures)']],
                'empty' => ['type' => 'text', 'label' => 'When there are none yet', 'max' => 120],
                'sidebar' => ['type' => 'select', 'label' => 'The sidebar', 'options' => ['right' => 'On the right', 'left' => 'On the left', 'none' => 'No sidebar']],
                'about' => ['type' => 'toggle', 'label' => 'In the sidebar: about this blog'],
                'about_title' => ['type' => 'text', 'label' => 'Its heading', 'max' => 80],
                'about_words' => ['type' => 'words', 'label' => 'Its words (empty: the site’s tagline)', 'max' => 600],
                'recent' => ['type' => 'toggle', 'label' => 'In the sidebar: recent posts'],
                'recent_title' => ['type' => 'text', 'label' => 'Its heading', 'max' => 80],
                'recent_count' => ['type' => 'select', 'label' => 'How many', 'options' => ['3' => 'Three', '4' => 'Four', '6' => 'Six', '8' => 'Eight']],
                'tags' => ['type' => 'toggle', 'label' => 'In the sidebar: tags'],
                'tags_title' => ['type' => 'text', 'label' => 'Its heading', 'max' => 80],
            ],
            'start' => [
                'style' => 'grid', 'empty' => 'Nothing in this chapter yet.', 'sidebar' => 'right',
                'about' => true, 'about_title' => 'About this blog', 'about_words' => '',
                'recent' => true, 'recent_title' => 'Recently', 'recent_count' => '4',
                'tags' => true, 'tags_title' => 'Tags',
            ],
            'style' => ['space' => 'snug'],
        ],
    ],

    // How every section can look.
    'looks' => [
        'background' => ['label' => 'Background', 'options' => ['none' => 'Paper', 'soft' => 'Soft', 'accent' => 'Accent', 'ink' => 'Dark', 'picture' => 'A picture']],
        'align' => ['label' => 'Words', 'options' => ['left' => 'Left', 'center' => 'Centred']],
        'space' => ['label' => 'Space', 'options' => ['snug' => 'Snug', 'normal' => 'Normal', 'roomy' => 'Roomy']],
        'width' => ['label' => 'Width', 'options' => ['narrow' => 'Narrow', 'normal' => 'Normal', 'wide' => 'Wide']],
        'show' => ['label' => 'Shows on', 'options' => ['all' => 'Every screen', 'computer' => 'Computers only', 'phone' => 'Phones only']],
    ],
    'look' => ['background' => 'none', 'picture' => '', 'align' => 'left', 'space' => 'normal', 'width' => 'normal', 'show' => 'all'],

    // Pages to start from.
    'starters' => [
        'blank' => ['name' => 'Almost blank', 'about' => 'Just a hero, to build up from', 'sections' => ['hero']],
        'landing' => ['name' => 'A landing page', 'about' => 'Hero, points, words and a picture, quotes, a call to action', 'sections' => ['hero', 'columns', 'text-picture', 'quotes', 'cta']],
        'about' => ['name' => 'About us', 'about' => 'Hero, your story, cards, quotes', 'sections' => ['hero', 'text-picture', 'cards', 'quotes']],
        'prices' => ['name' => 'Prices', 'about' => 'Hero, plans, questions, a call to action', 'sections' => ['hero', 'pricing', 'faq', 'cta']],
        'contact' => ['name' => 'Contact', 'about' => 'Hero, the contact form, questions', 'sections' => ['hero', 'contact', 'faq']],
        'shop' => ['name' => 'A shop or café', 'about' => 'Hero, the menu, opening hours, quotes, a call to action', 'sections' => ['hero', 'menu', 'hours', 'quotes', 'cta']],
        'team' => ['name' => 'Our people', 'about' => 'Hero, numbers, the people, how it works', 'sections' => ['hero', 'stats', 'team', 'steps']],
    ],
];
