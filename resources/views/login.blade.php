@extends('layouts.app')

@section('title', 'Sign In | ' . config('app.name', 'Personal Health Record'))

@section('content')
<div class="max-w-md mx-auto px-4 py-12">
    <div class="bg-card text-card-foreground shadow-md border border-border rounded-lg p-6">
        <h1 class="text-2xl font-bold mb-2">Sign In</h1>
        <p class="text-sm text-muted-foreground mb-6">
            Continue to the identity provider to sign in securely.
        </p>

        <a
            href="{{ route('oauth.redirect') }}"
            class="block w-full bg-primary text-primary-foreground py-2 px-4 rounded-md text-center font-medium hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-ring transition-colors"
        >
            Sign in
        </a>

    </div>
</div>
@endsection
