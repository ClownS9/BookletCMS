{{--
    Where a guarding insert puts its widget in a form (a CAPTCHA box, say), and
    where its "no" is shown. Nothing at all when no insert guards this form.
    Kept as it is while the form changes (wire:ignore), so the widget isn't wiped.
--}}

@props(['form'])
@php $widget = app(\App\Inserts\InsertManager::class)->guardWidget($form); @endphp
@if ($widget !== '')
    <div class="guard" data-guard-form="{{ $form }}" wire:ignore>{!! $widget !!}</div>
@endif
@error('guard') <p class="field__error" role="alert">{{ $message }}</p> @enderror
