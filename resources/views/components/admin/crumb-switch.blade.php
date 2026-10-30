@props(['switch'])
{{-- The section in the breadcrumb, with ⇕: the pages beside it, something
     new, and the section's settings, without reaching for the sidebar. --}}
<span class="crumb-switch"
      x-data="{
          open: false,
          toggle() { this.open ? this.close() : this.show() },
          show() { this.open = true; this.$nextTick(() => (this.$refs.menu.querySelector('[aria-current]') || this.$refs.menu.querySelector('a'))?.focus()) },
          close(back = false) { if (! this.open) return; this.open = false; if (back) this.$refs.button.focus() },
          move(step) {
              const links = [...this.$refs.menu.querySelectorAll('a')];
              const at = links.indexOf(document.activeElement);
              links[(at + step + links.length) % links.length]?.focus();
          },
      }"
      x-on:keydown.escape.stop="close(true)"
      x-on:click.outside="close()"
      x-on:focusout="$nextTick(() => { if (! $el.contains(document.activeElement)) close() })">
    <button type="button" class="crumb-switch__button" x-ref="button" x-on:click="toggle()"
            aria-haspopup="true" :aria-expanded="open ? 'true' : 'false'" aria-controls="crumb-switch-menu"
            title="{{ __('Go to another page in :section', ['section' => $switch['label']]) }}">
        <span>{{ $switch['label'] }}</span>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>
    </button>
    <div class="crumb-switch__menu" id="crumb-switch-menu" x-ref="menu" x-show="open" x-cloak
         x-transition:enter="crumb-switch-in" x-transition:enter-start="crumb-switch-in--from"
         x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)"
         x-on:keydown.home.prevent="$refs.menu.querySelector('a')?.focus()" x-on:keydown.end.prevent="[...$refs.menu.querySelectorAll('a')].pop()?.focus()">
        @foreach ($switch['groups'] as $group)
            @if ($group['title'] && count($switch['groups']) > 1)
                <p class="crumb-switch__title">{{ $group['title'] }}</p>
            @endif
            <ul role="list">
                @foreach ($group['items'] as $item)
                    <li>
                        <a href="{{ $item['href'] }}" wire:navigate @if ($item['active']) aria-current="page" @endif x-on:click="close()">
                            <i style="background: var(--{{ $item['tone'] }})" aria-hidden="true"></i>
                            <span>{{ $item['label'] }}</span>
                            @if ($item['active'])
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endforeach
        @if ($switch['create'] || $switch['configure'])
            <ul role="list" class="crumb-switch__foot">
                @if ($switch['create'])
                    <li><a href="{{ $switch['create']['href'] }}" wire:navigate x-on:click="close()"><x-admin.icon name="plus" size="14" /> <span>{{ $switch['create']['label'] }}</span></a></li>
                @endif
                @if ($switch['configure'])
                    <li><a href="{{ $switch['configure']['href'] }}" wire:navigate x-on:click="close()"><x-admin.icon name="settings" size="14" /> <span>{{ $switch['configure']['label'] }}</span></a></li>
                @endif
            </ul>
        @endif
    </div>
</span>
