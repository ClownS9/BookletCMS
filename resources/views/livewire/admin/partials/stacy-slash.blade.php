{{-- The "/" menu over Stacy's box: shortcuts, filtered as you type --}}
<ul class="slash" x-show="slashOpen()" x-cloak role="listbox" aria-label="Shortcuts">
    <template x-for="(cmd, i) in slashItems()" :key="cmd.name">
        <li role="option" :aria-selected="(i === slashAt).toString()">
            <button type="button" class="slash__item" :class="{ 'is-on': i === slashAt }"
                    x-on:mouseenter="slashAt = i" x-on:mousedown.prevent="pickSlash(cmd)">
                <code x-text="'/' + cmd.name"></code>
                <span x-text="cmd.hint"></span>
            </button>
        </li>
    </template>
</ul>
