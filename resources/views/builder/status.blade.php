{{--
    Draft, scheduled or published, as posts and pages have it. For a page
    that's on the site already, "scheduled" is for its changes: they go live
    then, and readers see what's there now until they do.
--}}
@php
    $tz = config('app.timezone');
    $now = now()->timezone($tz);
    $picked = $scheduled ? 'scheduled' : ($live ? 'published' : 'draft');
    $tried = ! empty($errors['at']) ? request()->input('payload.at') : null; // a time that wasn't right: shown again, to fix
    if ($tried !== null || ! empty($errors['at'])) {
        $picked = 'scheduled';
    }
    $at = is_string($tried) ? $tried : ($scheduled
        ? \Illuminate\Support\Carbon::parse($scheduled['at'])->timezone($tz)->format('Y-m-d\TH:i')
        : $now->copy()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i'));
    $choices = [
        'draft' => ['Draft', $isBlog
            ? ($live ? 'Readers see the blog as The cover has it. Your arrangement is kept.' : 'Readers see the blog as The cover has it.')
            : ($live ? 'Takes it off the site. It’s kept here.' : 'Only people who can edit it see it.')],
        'scheduled' => ['Scheduled', $live
            ? 'Your changes go live on a day and time you choose. Until then readers see what’s there now.'
            : 'It goes live on a day and time you choose.'],
        'published' => ['Published', $live && $where === 'changed' ? 'Your changes go live now.' : 'On the site now.'],
    ];
@endphp
<div class="d-statusblock">
<form class="d-form d-status" data-form="status" novalidate>
    <fieldset class="d-field">
        <legend class="d-label">{{ __('Status') }}</legend>
        @foreach ($choices as $value => [$name, $about])
            <label class="d-radio">
                <input type="radio" name="status" value="{{ $value }}" @checked($picked === $value)>
                <span><b>{{ $name }}</b><small>{{ __($about) }}</small></span>
            </label>
        @endforeach
        <label class="d-field d-status__when">
            <span>{{ __('Day and time') }}</span>
            <input type="datetime-local" name="at" value="{{ $at }}">
            <small>The site’s time: it’s {{ $now->format('g:ia') }} there now.</small>
            @if (! empty($errors['at'])) <em class="d-error">{{ $errors['at'] }}</em> @endif
        </label>
    </fieldset>
    <button type="submit" class="d-btn">{{ __('Save status') }}</button>
</form>

@if ($scheduled)
    <div class="d-scheduled">
        <p class="d-note"><b>{{ $live ? __('Changes go live') : __('Goes live') }} {{ $scheduled['when'] }}.</b>
            @if ($scheduled['stale']) You’ve changed it since: those changes aren’t in it yet. @endif
        </p>
        @if ($scheduled['stale'])
            <button type="button" class="d-link" data-do="reschedule">{{ __('Include my latest changes') }}</button>
        @endif
        <button type="button" class="d-link" data-do="unschedule">{{ __('Don’t schedule it') }}</button>
    </div>
@endif
</div>
