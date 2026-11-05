<?php

namespace App\Http\Controllers\Lab;

use App\Enums\StockMovementReason;
use App\Http\Controllers\Controller;
use App\Lab\Realtime\LabBroadcast;
use App\Lab\Realtime\LabBroadcastNow;
use App\Lab\Realtime\LabNotification;
use App\Lab\Realtime\LabSignal;
use App\Lab\Realtime\LabTrace;
use App\Models\Stock;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * /lab/realtime: buttons fire events; the page listens over WebSocket (Echo + Reverb)
 * and draws a timeline of what arrived, from where (web/worker) and how fast.
 * Buttons post with fetch() and the X-Socket-ID header (needed for toOthers()).
 */
class LabRealtimeController extends Controller
{
    /**
     * Buttons on the page, grouped (labels: lang/{uk,en}/lab.php => realtime.*).
     */
    public const GROUPS = [
        'listeners' => ['signal', 'signal_halt'],
        'broadcasting' => ['broadcast_queued', 'broadcast_now', 'broadcast_to_others'],
        'channels' => ['stock_plus', 'stock_minus', 'notify_me'],
    ];

    public function index(): View
    {
        return view('lab.realtime', [
            'groups' => self::GROUPS,
            'stock' => $this->demoStock(),
            'pending' => DB::table('jobs')->selectRaw('queue, count(*) as jobs')->groupBy('queue')->pluck('jobs', 'queue'),
            'broadcaster' => config('broadcasting.default'),
            'queue' => config('queue.default'),
        ]);
    }

    public function dispatch(Request $request, string $variant): JsonResponse|RedirectResponse
    {
        abort_unless(in_array($variant, array_merge(...array_values(self::GROUPS)), true), 404);

        try {
            $this->run($request, $variant);
            $message = __('lab.realtime.flash.dispatched', ['variant' => __('lab.realtime.variants.'.$variant.'.title')]);
            $ok = true;
        } catch (BroadcastException $e) {
            // ShouldBroadcastNow without ShouldRescue: Reverb down => the request fails.
            $message = __('lab.realtime.flash.broadcast_failed', ['message' => $e->getMessage()]);
            $ok = false;
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 503);
        }

        return redirect()->route('lab.realtime')->with('status', $message);
    }

    private function run(Request $request, string $variant): void
    {
        match ($variant) {
            // A plain event: one sync and two queued listeners (AppServiceProvider::configureLab()).
            'signal' => $this->signal($variant),
            'signal_halt' => $this->signal($variant, halt: true),
            // event() and broadcast() both work for ShouldBroadcast events; broadcast()
            // returns a PendingBroadcast with ->toOthers().
            'broadcast_queued' => broadcast(new LabBroadcast($variant)),
            'broadcast_now' => broadcast(new LabBroadcastNow($variant)),
            // Skips the connection whose socket id came in the X-Socket-ID header.
            'broadcast_to_others' => broadcast(new LabBroadcastNow($variant))->toOthers(),
            'stock_plus' => $this->changeStock(+1),
            'stock_minus' => $this->changeStock(-1),
            'notify_me' => $this->notifyMe($request),
        };
    }

    private function signal(string $variant, bool $halt = false): void
    {
        LabTrace::dispatch($variant, 'dispatched');
        LabSignal::dispatch($variant, $halt);
        LabTrace::dispatch($variant, 'request_done');
    }

    /**
     * A real write through Eloquent: StockObserver books the movement and dispatches
     * BookStockChanged (queued => needs the worker). Open the book page in another
     * window to watch the badge.
     */
    private function changeStock(int $delta): void
    {
        $stock = $this->demoStock();
        abort_if($stock === null, 404);

        $stock->movementReason = StockMovementReason::Adjustment;
        $stock->quantity = max(0, $stock->quantity + $delta);
        $stock->save();
    }

    private function notifyMe(Request $request): void
    {
        abort_if($request->user() === null, 403, __('lab.realtime.flash.login_required'));

        $request->user()->notify(new LabNotification);
    }

    /**
     * The first stock row: a fixed book for experiment 4.
     */
    private function demoStock(): ?Stock
    {
        return Stock::with('book:id,title,slug')->orderBy('id')->first();
    }
}
