@php
    $parts = [
        'start' => 'Starting',
        'words' => 'Words and pictures',
        'sections' => 'Sections',
        'look' => 'How a section looks',
        'reuse' => 'Using things again',
        'publish' => 'Publishing',
        'together' => 'Working together',
        'stacy' => 'Stacy',
        'stop' => 'Going back to normal',
        'keys' => 'Keys',
    ];
@endphp
<div style="display: contents">
    {{-- Left page: what it is, and where everything is --}}
    <section class="page page--left page--stack" style="flex-basis: 380px">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.contents') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Contents') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Building pages') }}</h1>
                <p class="lede" style="margin-top: 12px; font-size: 15px">{{ __('Any page or post can be made of sections (a hero, cards, prices, questions…) and changed right where readers see it. It stays the same page: its address, its chapter and its place in the menus don’t change.') }}</p>
            </div>
        </div>

        <x-admin.card>
            <nav aria-label="{{ __('In this guide') }}">
                <h2 class="eyebrow section-label">{{ __('In this guide') }}</h2>
                <ol class="guide-toc">
                    @foreach ($parts as $id => $title)
                        <li><a href="#guide-{{ $id }}">{{ $title }}</a></li>
                    @endforeach
                </ol>
            </nav>

            <div class="guide-try">
                <p class="guide-try__title">{{ __('Try it') }}</p>
                <p class="field__hint" style="margin: 0">{{ __('Open a page in the book and press') }} <b>{{ __('Build it on the page') }}</b>{{ __(', or open it on the site and press') }} <b>{{ __('Build this page') }}</b> {{ __('in the corner. Nothing changes for readers until you publish.') }}</p>
                <span class="guide-try__go">
                    @if ($pagesUrl) <a href="{{ $pagesUrl }}" class="btn btn--sm" wire:navigate>{{ __('Your pages') }}</a> @endif
                    @if ($blogUrl) <a href="{{ $blogUrl }}" class="btn btn--sm">{{ __('Arrange the blog page') }}</a> @endif
                </span>
            </div>
        </x-admin.card>

        <x-admin.folio side="left" style="margin-top: auto" />
    </section>

    {{-- Right page: the guide --}}
    <section class="page page--right page--stack" style="--ribbon: var(--mint)" tabindex="0" aria-label="{{ __('The guide') }}">
        <div class="ribbon" aria-hidden="true"></div>
        <x-admin.card>
            <div class="guide ribbon-clear">
                <section id="guide-start">
                    <h2 class="heading heading--sub">{{ __('Starting') }}</h2>
                    <p>{{ __('A page you build starts from what it already has: its words, headings and pictures become sections. A page with nothing in it yet starts as a simple landing page.') }}</p>
                    <p>{{ __('To start again from a ready-made arrangement, open the drawer’s') }} <b>{{ __('Page') }}</b> {{ __('tab and choose') }} <b>{{ __('Start from a layout') }}</b>:</p>
                    <ul>
                        @foreach ($starters as $starter)
                            <li><b>{{ $starter['name'] }}</b>: {{ __($starter['about']) }}.</li>
                        @endforeach
                    </ul>
                    <p>{{ __('The blog page can be arranged the same way (featured posts, categories, the posts themselves). Until you publish an arrangement, it looks as The cover has it.') }}</p>
                </section>

                <section id="guide-words">
                    <h2 class="heading heading--sub">{{ __('Words and pictures') }}</h2>
                    <p>{{ __('Click any words on the page and type.') }} <kbd>{{ __('Enter') }}</kbd> {{ __('finishes a line;') }} <kbd>{{ __('Escape') }}</kbd> {{ __('finishes too. In longer words, select some to make them') }} <b>{{ __('bold') }}</b>, <i>{{ __('italic') }}</i> {{ __('or a link with the little bar that appears.') }}</p>
                    <p>{{ __('Click a picture, or') }} <b>{{ __('Choose a picture') }}</b>{{ __(', to pick one from Media. With a picture selected, the drawer shows its') }} <b>{{ __('description') }}</b> {{ __('(read out to people who can’t see it) and lets you click the spot that matters, so it stays in view when the picture is cropped. Both are saved to the picture in Media, so they’re right everywhere it’s used.') }}</p>
                    <p>{{ __('A button’s words are typed on the page; where it goes is set in the drawer.') }}</p>
                </section>

                <section id="guide-sections">
                    <h2 class="heading heading--sub">{{ __('Sections') }}</h2>
                    <p>{{ __('Hover over a section for its tools: move it up or down, make a copy, add one after it, open its settings, or take it out. Drag it by its handle to move it further, or drag it in the drawer’s') }} <b>{{ __('All sections') }}</b> {{ __('tab, which lists every section on the page.') }}</p>
                    <p>{{ __('To add one, press') }} <b>+</b> {{ __('between sections or') }} <b>{{ __('Add a section') }}</b> {{ __('in the drawer, and choose what kind.') }} <b>{{ __('Columns of your own') }}</b> {{ __('puts two to four columns side by side, each with any of a picture, a heading, words and a button.') }} <b>{{ __('A form') }}</b> {{ __('puts one of your own forms on the page (a sign-up, an RSVP, an order: make them under Comments › Forms); answers arrive in the Inbox.') }}</p>
                </section>

                <section id="guide-look">
                    <h2 class="heading heading--sub">{{ __('How a section looks') }}</h2>
                    <p>{{ __('Under') }} <b>{{ __('How it looks') }}</b> {{ __('in the drawer: its background (paper, soft, accent, dark or a picture), whether the words are centred, how much space it has and how wide it is.') }}</p>
                    <p><b>{{ __('Shows on') }}</b> {{ __('keeps a section to computers only or phones only, say a long table on computers and a short list on phones.') }} <b>{{ __('Phone view') }}</b> {{ __('in the bar shows the draft at phone size.') }}</p>
                </section>

                <section id="guide-reuse">
                    <h2 class="heading heading--sub">{{ __('Using things again') }}</h2>
                    <p><b>{{ __('Shared sections.') }}</b> {{ __('Opening hours, a call to action, anything you want the same on several pages: select it and choose') }} <b>{{ __('Use it on other pages too') }}</b>{{ __('. It’s then under') }} <b>{{ __('Add a section › Shared') }}</b> {{ __('everywhere. Change it once and it changes on every page, when published.') }} <b>{{ __('Make it this page’s own') }}</b> {{ __('lets one page go its own way.') }}</p>
                    <p><b>{{ __('Sending a copy.') }}</b> <b>{{ __('Send a copy to another page') }}</b> {{ __('puts a copy at the end of another page’s draft, to change there as you like.') }}</p>
                    <p><b>{{ __('Layouts.') }}</b> {{ __('In the') }} <b>{{ __('Page') }}</b> {{ __('tab,') }} <b>{{ __('Save as a layout') }}</b> {{ __('keeps this page’s arrangement to start other pages from.') }}</p>
                </section>

                <section id="guide-publish">
                    <h2 class="heading heading--sub">{{ __('Publishing') }}</h2>
                    <p>{{ __('What you change is a draft: readers see the page as it was last published until you press') }} <b>{{ __('Publish') }}</b>{{ __('. The bar says where it stands: on the site, changes not published, scheduled, or not on the site.') }}</p>
                    <p>{{ __('In the') }} <b>{{ __('Page') }}</b> {{ __('tab you can also:') }}</p>
                    <ul>
                        <li><b>{{ __('Schedule it') }}</b> {{ __('for a day and time. Changes made after that wait, unless you add them.') }}</li>
                        <li>{{ __('Read the') }} <b>{{ __('checks') }}</b>{{ __(': example words still there, buttons that go nowhere, pictures without a description, and the like. Click one to go to that section.') }}</li>
                        <li>{{ __('Look back at the') }} <b>{{ __('history') }}</b>: every published version is kept (the last {{ \App\Models\PageBuildVersion::KEEP }}), to look at and put back.</li>
                        <li><b>{{ __('Go back to what’s on the site') }}</b>{{ __(', throwing away changes not yet published, or take the page off the site.') }}</li>
                        <li>{{ __('Change its name, address and description. If a live page’s address changes, the old one sends readers on.') }}</li>
                    </ul>
                    <p><b>{{ __('Undo') }}</b> {{ __('and') }} <b>{{ __('Redo') }}</b> {{ __('in the bar step back and forward through your changes. A run of typing in the same words is one step.') }}</p>
                </section>

                <section id="guide-together">
                    <h2 class="heading heading--sub">{{ __('Working together') }}</h2>
                    <p>{{ __('If someone else is building the same page, the bar says so. If they change it while you’re there, your page is brought up to date. Should you both change it at once, the later change is stopped and you’re shown theirs, to make yours again, so nobody’s work is lost.') }}</p>
                    <p><b>{{ __('Proof copies') }}</b> {{ __('(in the') }} <b>{{ __('Page') }}</b> {{ __('tab) are private links to the draft for someone without an account, a client, say. They last as long as you choose, and if you let them, they can leave notes, which you see in the same place. Stop a link any time.') }}</p>
                </section>

                <section id="guide-stacy">
                    <h2 class="heading heading--sub">{{ __('Stacy') }}</h2>
                    <p>{{ __('With a section selected,') }} <b>{{ __('Ask Stacy to write it') }}</b> {{ __('and say what it’s about. She writes its words only (pictures and links stay as they are) and leaves anything she doesn’t know in [brackets] for you. Undo takes it back.') }}</p>
                    <p>{{ __('When Stacy translates a built page (') }}<b>{{ __('Translate with Stacy…') }}</b> {{ __('in the book), the translation is built too: the same sections and pictures, with every heading, line and button in the other language. It’s a draft until you publish it.') }}</p>
                </section>

                <section id="guide-stop">
                    <h2 class="heading heading--sub">{{ __('Going back to normal') }}</h2>
                    <p>{{ __('In the') }} <b>{{ __('Page') }}</b> {{ __('tab,') }} <b>{{ __('Stop building it') }}</b> {{ __('lets the page be drawn as Booklet draws it again, with its own words from the book. The sections you built are let go.') }}</p>
                </section>

                <section id="guide-keys">
                    <h2 class="heading heading--sub">{{ __('Keys') }}</h2>
                    <dl class="guide-keys">
                        <div><dt><kbd>{{ __('Ctrl') }}</kbd> <kbd>Z</kbd></dt><dd>{{ __('Undo') }}</dd></div>
                        <div><dt><kbd>{{ __('Ctrl') }}</kbd> <kbd>{{ __('Shift') }}</kbd> <kbd>Z</kbd> {{ __('or') }} <kbd>{{ __('Ctrl') }}</kbd> <kbd>Y</kbd></dt><dd>{{ __('Redo') }}</dd></div>
                        <div><dt><kbd>{{ __('Ctrl') }}</kbd> <kbd>B</kbd> · <kbd>I</kbd> · <kbd>K</kbd></dt><dd>{{ __('Bold, italic, a link (while typing)') }}</dd></div>
                        <div><dt><kbd>{{ __('Enter') }}</kbd> {{ __('or') }} <kbd>{{ __('Escape') }}</kbd></dt><dd>{{ __('Finish typing a line') }}</dd></div>
                    </dl>
                    <p class="field__hint">{{ __('On a Mac,') }} <kbd>⌘</kbd> {{ __('instead of') }} <kbd>{{ __('Ctrl') }}</kbd>.</p>
                </section>
            </div>
        </x-admin.card>

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
