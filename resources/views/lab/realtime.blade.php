<x-layouts.app :title="__('lab.realtime.title')">
    <p class="-mt-2 mb-2 max-w-3xl text-sm text-stone-600">{{ __('lab.realtime.intro') }}</p>
    <p class="mb-1 text-xs text-stone-500">{{ __('lab.realtime.config', ['broadcaster' => $broadcaster, 'queue' => $queue]) }}</p>
    <p class="mb-6 text-xs text-stone-500">{{ __('lab.realtime.services_hint') }}</p>

    <div id="lab-realtime" class="grid gap-8 xl:grid-cols-[1fr_28rem]" data-user-id="{{ auth()->id() }}">
        <div class="space-y-6">
            @foreach ($groups as $group => $variants)
                <section>
                    <h2 class="mb-1 text-lg font-semibold">{{ __('lab.realtime.groups.'.$group.'.title') }}</h2>
                    <p class="mb-3 text-xs text-stone-600">{{ __('lab.realtime.groups.'.$group.'.text') }}</p>
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($variants as $variant)
                            <x-card>
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <code class="text-sm font-semibold">{{ __('lab.realtime.variants.'.$variant.'.title') }}</code>
                                        <p class="mt-1 text-xs text-stone-600">{{ __('lab.realtime.variants.'.$variant.'.text') }}</p>
                                    </div>
                                    <form method="POST" action="{{ route('lab.realtime.dispatch', $variant) }}" data-lab-form>
                                        @csrf
                                        <button type="submit" class="btn-secondary shrink-0" data-variant="{{ $variant }}">{{ __('lab.realtime.dispatch') }}</button>
                                    </form>
                                </div>
                            </x-card>
                        @endforeach

                        @if ($group === 'channels')
                            {{-- Experiment 6: client-only, no server variant — Echo asks /broadcasting/auth. --}}
                            <x-card>
                                <code class="text-sm font-semibold">{{ __('lab.realtime.subscribe.title') }}</code>
                                <p class="mt-1 text-xs text-stone-600">{{ __('lab.realtime.subscribe.text') }}</p>
                                <form class="mt-2 flex gap-2" data-subscribe-form>
                                    <label class="sr-only" for="subscribe-user">{{ __('lab.realtime.subscribe.user_id') }}</label>
                                    <input id="subscribe-user" type="number" min="1" name="user_id" class="form-input max-w-28" value="{{ (auth()->id() ?? 0) + 1 }}">
                                    <button type="submit" class="btn-secondary">{{ __('lab.realtime.subscribe.button') }}</button>
                                </form>
                            </x-card>
                        @endif
                    </div>

                    @if ($group === 'channels')
                        <p class="mt-3 text-xs text-stone-600">
                            @if ($stock)
                                {{ __('lab.realtime.demo_book') }}
                                <a href="{{ route('books.show', $stock->book) }}" target="_blank" class="text-amber-700 hover:underline">{{ $stock->book->title }}</a>
                                ({{ __('lab.realtime.demo_quantity', ['quantity' => $stock->quantity]) }})
                            @else
                                {{ __('lab.realtime.no_stock') }}
                            @endif
                        </p>
                    @endif
                </section>
            @endforeach

            <section>
                <h2 class="mb-1 text-lg font-semibold">{{ __('lab.realtime.import.title') }}</h2>
                <p class="text-xs text-stone-600">{{ __('lab.realtime.import.text') }}
                    <a href="{{ route('admin.imports.index') }}" class="text-amber-700 hover:underline">{{ __('lab.realtime.import.link') }}</a>
                </p>
            </section>
        </div>

        <aside class="space-y-6">
            <x-card>
                <x-slot:header>{{ __('lab.realtime.connection.title') }}</x-slot:header>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 text-sm">
                    <dt class="text-stone-500">{{ __('lab.realtime.connection.state') }}</dt>
                    <dd><span class="font-semibold" data-connection-state>{{ __('lab.realtime.connection.states.no_echo') }}</span></dd>
                    <dt class="text-stone-500">{{ __('lab.realtime.connection.socket_id') }}</dt>
                    <dd><code data-socket-id>—</code></dd>
                </dl>
            </x-card>

            <x-card>
                <x-slot:header>{{ __('lab.realtime.timeline') }}</x-slot:header>
                <ol class="max-h-[32rem] space-y-1 overflow-y-auto text-xs" data-timeline>
                    <li class="text-stone-500" data-timeline-empty>{{ __('lab.realtime.timeline_empty') }}</li>
                </ol>
                <x-slot:footer>
                    <button type="button" class="btn-secondary" data-timeline-clear>{{ __('lab.realtime.timeline_clear') }}</button>
                </x-slot:footer>
            </x-card>

            <x-card>
                <x-slot:header>{{ __('lab.realtime.pending') }}</x-slot:header>
                <ul class="text-sm">
                    @forelse ($pending as $name => $count)
                        <li class="flex justify-between"><code>{{ $name }}</code><span>{{ $count }}</span></li>
                    @empty
                        <li class="text-stone-500">{{ __('lab.realtime.no_pending') }}</li>
                    @endforelse
                </ul>
                <x-slot:footer><span class="text-xs text-stone-500">{{ __('lab.realtime.pending_hint') }}</span></x-slot:footer>
            </x-card>
        </aside>
    </div>

    {{-- Texts for resources/js/lab-realtime.js (":name" placeholders are replaced in JS). --}}
    <script type="application/json" id="lab-realtime-i18n">@json(__('lab.realtime.js'))</script>
    @push('scripts')
        @vite('resources/js/lab-realtime.js')
    @endpush
</x-layouts.app>
