<?php

declare(strict_types=1);

namespace App\Services\Display;

use App\Models\DisplayDevice;
use Illuminate\Http\Request;

/**
 * A paired display is remembered in its browser session by the device id
 * and the credential hash at pairing time. Deactivating the device or
 * rotating its key invalidates every paired session immediately.
 */
final class DisplaySession
{
    public function pair(Request $request, DisplayDevice $device): void
    {
        $request->session()->regenerate();
        $request->session()->put($this->key($device), $device->credential_hash);
    }

    public function forget(Request $request, DisplayDevice $device): void
    {
        $request->session()->forget($this->key($device));
    }

    public function isPaired(Request $request, DisplayDevice $device): bool
    {
        $stored = $request->session()->get($this->key($device));

        return $device->is_active && is_string($stored) && hash_equals($device->credential_hash, $stored);
    }

    private function key(DisplayDevice $device): string
    {
        return 'display_devices.'.$device->id;
    }
}
