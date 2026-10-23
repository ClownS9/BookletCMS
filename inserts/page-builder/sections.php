<?php

/*
 * The page builder's sections: what each one holds, how it starts, and the
 * looks every section can wear. insert.php reads this; the views under
 * views/sections/ draw them.
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
    ],

    // How every section can look.
    'looks' => [
        'background' => ['label' => 'Background', 'options' => ['none' => 'Paper', 'soft' => 'Soft', 'accent' => 'Accent', 'ink' => 'Dark', 'picture' => 'A picture']],
        'align' => ['label' => 'Words', 'options' => ['left' => 'Left', 'center' => 'Centred']],
        'space' => ['label' => 'Space', 'options' => ['snug' => 'Snug', 'normal' => 'Normal', 'roomy' => 'Roomy']],
        'width' => ['label' => 'Width', 'options' => ['narrow' => 'Narrow', 'normal' => 'Normal', 'wide' => 'Wide']],
    ],
    'look' => ['background' => 'none', 'picture' => '', 'align' => 'left', 'space' => 'normal', 'width' => 'normal'],

    // Pages to start from.
    'starters' => [
        'blank' => ['name' => 'Almost blank', 'about' => 'Just a hero, to build up from', 'sections' => ['hero']],
        'landing' => ['name' => 'A landing page', 'about' => 'Hero, points, words and a picture, quotes, a call to action', 'sections' => ['hero', 'columns', 'text-picture', 'quotes', 'cta']],
        'about' => ['name' => 'About us', 'about' => 'Hero, your story, cards, quotes', 'sections' => ['hero', 'text-picture', 'cards', 'quotes']],
        'prices' => ['name' => 'Prices', 'about' => 'Hero, plans, questions, a call to action', 'sections' => ['hero', 'pricing', 'faq', 'cta']],
        'contact' => ['name' => 'Contact', 'about' => 'Hero, the contact form, questions', 'sections' => ['hero', 'contact', 'faq']],
    ],
];
