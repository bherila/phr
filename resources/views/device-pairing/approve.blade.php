@extends('layouts.app')

@section('title', 'Device Pairing | Sinus Sentinel')

@section('content')
<div class="max-w-md mx-auto px-4 py-12">
    <div class="bg-card text-card-foreground shadow-md border border-border rounded-lg p-6">
        <h1 class="text-2xl font-bold mb-2">Pair Sinus Sentinel</h1>
        <p class="text-sm text-muted-foreground mb-6">
            This device wants to connect to your Sinus Sentinel account. Approve it only if you
            recognize it.
        </p>

        <dl class="mb-6 space-y-2 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Device</dt>
                <dd class="font-medium text-right">{{ $deviceName }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Device ID</dt>
                <dd class="font-mono text-xs text-right break-all">{{ $deviceId }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">Signed in as</dt>
                <dd class="font-medium text-right">{{ auth()->user()->email }}</dd>
            </div>
        </dl>

        <form method="POST" action="{{ route('device-pairing.approve') }}" class="mb-3">
            @csrf
            <input type="hidden" name="device_id" value="{{ $deviceId }}">
            <input type="hidden" name="name" value="{{ $deviceName }}">
            <input type="hidden" name="code_challenge" value="{{ $codeChallenge }}">
            <input type="hidden" name="redirect_uri" value="{{ $redirectUri }}">
            <button
                type="submit"
                class="block w-full bg-primary text-primary-foreground py-2 px-4 rounded-md text-center font-medium hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-ring transition-colors"
            >
                Approve device
            </button>
        </form>

        <form method="POST" action="{{ route('device-pairing.deny') }}">
            @csrf
            <input type="hidden" name="device_id" value="{{ $deviceId }}">
            <input type="hidden" name="name" value="{{ $deviceName }}">
            <input type="hidden" name="code_challenge" value="{{ $codeChallenge }}">
            <input type="hidden" name="redirect_uri" value="{{ $redirectUri }}">
            <button
                type="submit"
                class="block w-full bg-card text-muted-foreground border border-border py-2 px-4 rounded-md text-center font-medium hover:text-foreground focus:outline-none focus:ring-2 focus:ring-ring transition-colors"
            >
                Deny
            </button>
        </form>
    </div>
</div>
@endsection
