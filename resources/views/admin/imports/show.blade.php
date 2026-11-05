<x-layouts.app :title="__('ui.imports.show_title', ['id' => $import->id])">
    <p class="-mt-4 mb-6 text-sm text-stone-500">
        <a href="{{ route('admin.imports.index') }}" class="hover:underline">{{ __('ui.imports.title') }}</a>
        · {{ $import->original_name }} → {{ $import->warehouse->name }} · {{ $import->user?->name }}
    </p>

    {{-- The page renders the current state; the script below refreshes it from the JSON
         endpoint every 2 s until the import is finished. --}}
    <x-card id="import-progress" data-import-id="{{ $import->id }}" data-url="{{ route('admin.imports.progress', $import) }}" data-finished="{{ $progress['finished'] ? '1' : '0' }}">
        <div class="mb-2 flex items-baseline justify-between text-sm">
            <span class="font-semibold" data-field="status_label">{{ $progress['status_label'] }}</span>
            <span><span data-field="percentage">{{ $progress['percentage'] }}</span>%</span>
        </div>
        <div class="h-3 overflow-hidden rounded bg-stone-200">
            <div class="h-3 bg-amber-500 transition-all" data-bar style="width: {{ $progress['percentage'] }}%"></div>
        </div>
        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
            @foreach (['total', 'processed', 'imported', 'failed'] as $field)
                <div class="rounded border border-stone-200 px-3 py-2">
                    <dt class="text-xs text-stone-500">{{ __('ui.imports.progress.'.$field) }}</dt>
                    <dd class="text-lg font-semibold" data-field="{{ $field }}">{{ $progress[$field] }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="mt-3 text-xs text-stone-500">
            {{ __('ui.imports.progress.batch') }}:
            <span data-field="batch">{{ $progress['batch'] ? $progress['batch']['progress'].'% · '.$progress['batch']['pending_jobs'].' / '.$progress['batch']['total_jobs'].' · ✗ '.$progress['batch']['failed_jobs'] : '—' }}</span>
        </p>
        <p class="mt-1 text-xs text-stone-500" data-hint @if ($progress['finished']) hidden @endif>{{ __('ui.imports.progress.worker_hint') }}</p>
    </x-card>

    <x-card class="mt-6">
        <x-slot:header class="flex items-center justify-between">
            <span>{{ __('ui.imports.errors_title') }}</span>
            <a href="{{ route('admin.imports.errors', $import) }}" class="text-sm font-normal text-amber-700 hover:underline">{{ __('ui.imports.download_errors') }}</a>
        </x-slot:header>
        <ul class="space-y-1 text-sm" data-errors>
            @forelse ($progress['errors'] as $row => $messages)
                <li><span class="font-mono text-stone-500">{{ $row }}</span>: {{ implode(' ', $messages) }}</li>
            @empty
                <li class="text-stone-500">{{ __('ui.imports.no_errors') }}</li>
            @endforelse
        </ul>
        <x-slot:footer>{{ __('ui.imports.errors_preview') }}</x-slot:footer>
    </x-card>

    @push('scripts')
        <script>
            // Polling (stage 9) + push (stage 10). The socket message (ImportProgressed on
            // the private channel imports.{id}) is only a signal: on it the page fetches
            // the same JSON it polls for. Polling stays as the fallback — every 2 s
            // without a WebSocket connection, every 10 s with one (a missed message).
            // DOMContentLoaded: Vite's module scripts (window.Echo) run before it.
            document.addEventListener('DOMContentLoaded', () => {
                const box = document.getElementById('import-progress');
                if (box.dataset.finished === '1') return;

                const set = (field, value) => box.querySelector(`[data-field="${field}"]`).textContent = value;
                const errorsList = document.querySelector('[data-errors]');
                const channel = `imports.${box.dataset.importId}`;
                const live = () => window.Echo?.connector.pusher.connection.state === 'connected';
                let finished = false;

                const tick = async (reschedule = true) => {
                    if (finished) return;
                    const response = await fetch(box.dataset.url, {headers: {Accept: 'application/json'}});
                    if (!response.ok) return reschedule && setTimeout(tick, 5000);
                    const data = await response.json();

                    ['status_label', 'percentage', 'total', 'processed', 'imported', 'failed'].forEach((f) => set(f, data[f]));
                    box.querySelector('[data-bar]').style.width = data.percentage + '%';
                    if (data.batch) {
                        set('batch', `${data.batch.progress}% · ${data.batch.pending_jobs} / ${data.batch.total_jobs} · ✗ ${data.batch.failed_jobs}`);
                    }
                    const rows = Object.entries(data.errors);
                    if (rows.length) {
                        errorsList.replaceChildren(...rows.map(([row, messages]) => {
                            const li = document.createElement('li');
                            li.textContent = `${row}: ${messages.join(' ')}`; // textContent: no HTML injection
                            return li;
                        }));
                    }

                    if (data.finished) {
                        finished = true;
                        box.querySelector('[data-hint]').hidden = true;
                        window.Echo?.leave(channel);
                    } else if (reschedule) {
                        setTimeout(tick, live() ? 10000 : 2000);
                    }
                };

                window.Echo?.private(channel).listen('.import.progressed', () => tick(false));
                setTimeout(tick, 1000);
            });
        </script>
    @endpush
</x-layouts.app>
