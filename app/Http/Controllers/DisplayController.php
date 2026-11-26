<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DisplayDevice;
use App\Services\Display\DisplaySession;
use App\Services\Display\DisplaySnapshot;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The public display: pairing with a device key, the sanitized snapshot
 * and private-channel authorization. None of these endpoints use staff
 * authentication; every one requires a paired, active device.
 */
final class DisplayController extends Controller
{
    public function __construct(private readonly DisplaySession $session) {}

    public function show(Request $request, DisplayDevice $device): View
    {
        if (! $this->session->isPaired($request, $device)) {
            return view('display.pair', ['device' => $device]);
        }

        return view('display.screen', [
            'device' => $device,
            'reverb' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
            ],
        ]);
    }

    public function pair(Request $request, DisplayDevice $device): RedirectResponse
    {
        $key = $request->input('key');

        // One generic message for unknown, wrong or inactive devices.
        if (! $device->is_active || ! is_string($key) || ! $device->matchesKey(trim($key))) {
            return back()->withErrors(['key' => __('This display key is not valid.')]);
        }

        $this->session->pair($request, $device);
        $device->forceFill(['last_seen_at' => now()])->save();

        return redirect()->route('display.show', $device);
    }

    public function unpair(Request $request, DisplayDevice $device): RedirectResponse
    {
        $this->session->forget($request, $device);

        return redirect()->route('display.show', $device);
    }

    public function snapshot(Request $request, DisplayDevice $device, DisplaySnapshot $snapshot): JsonResponse
    {
        abort_unless($this->session->isPaired($request, $device), 403);

        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->save();
        }

        return response()->json($snapshot->for($device))->header('Cache-Control', 'no-store');
    }

    /**
     * Pusher-protocol private channel authorization for the device's own channel only.
     */
    public function authorizeChannel(Request $request, DisplayDevice $device): JsonResponse
    {
        abort_unless($this->session->isPaired($request, $device), 403);

        $socketId = $request->input('socket_id');
        $channel = $request->input('channel_name');

        if (! is_string($socketId) || preg_match('/^\d+\.\d+$/', $socketId) !== 1 || $channel !== 'private-'.$device->channelName()) {
            abort(403);
        }

        $key = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');
        abort_unless(is_string($key) && $key !== '' && is_string($secret) && $secret !== '', 503);

        return response()->json([
            'auth' => $key.':'.hash_hmac('sha256', $socketId.':'.$channel, $secret),
        ]);
    }
}
