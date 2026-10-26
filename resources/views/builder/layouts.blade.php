{{--
    Starting again from a layout (one Booklet comes with, or a page saved
    as one), and saving this page as a layout.
--}}
@if ($layouts)
    @php $confirm = fn ($name) => 'Start again from “'.$name.'”? What’s on the page now goes (Undo brings it back).'; @endphp
    <details class="d-layouts" @if (in_array(request()->input('action'), ['save-layout', 'forget-layout'], true) || ! empty($errors['layout'])) open @endif>
        <summary>Start from a layout</summary>
        <ul class="d-layouts__list">
            @foreach ($layouts['saved'] as $l)
                <li class="d-layouts__item">
                    <button type="button" class="d-layouts__pick" data-do="layout" data-payload="{{ json_encode($l['key']) }}" data-confirm="{{ $confirm($l['name']) }}">
                        <b>{{ $l['name'] }} <span class="d-tag">saved</span></b><small>{{ $l['about'] }}</small>
                    </button>
                    <button type="button" class="d-icon d-danger" data-do="forget-layout" data-payload="{{ $l['id'] }}" data-confirm="Forget the layout “{{ $l['name'] }}”? Pages started from it stay as they are." aria-label="Forget “{{ $l['name'] }}”">×</button>
                </li>
            @endforeach
            @foreach ($layouts['starters'] as $l)
                <li class="d-layouts__item">
                    <button type="button" class="d-layouts__pick" data-do="layout" data-payload="{{ json_encode($l['key']) }}" data-confirm="{{ $confirm($l['name']) }}">
                        <b>{{ $l['name'] }}</b><small>{{ $l['about'] }}</small>
                    </button>
                </li>
            @endforeach
        </ul>
        <form class="d-form d-layouts__save" data-form="save-layout">
            <label class="d-field">
                <span>Save this page as a layout</span>
                <input type="text" name="name" maxlength="80" placeholder="Event page, Product page…" value="{{ request()->input('action') === 'save-layout' && ! empty($errors['layout']) ? request()->input('payload.name') : '' }}">
                @if (! empty($errors['layout'])) <em class="d-error">{{ $errors['layout'] }}</em> @endif
                <small>Its sections, words and pictures, to start other pages from.</small>
            </label>
            <button type="submit" class="d-btn">Save as a layout</button>
        </form>
    </details>
@endif
