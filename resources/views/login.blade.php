@extends('layouts.app')

@section('title', 'Sign In | ' . config('app.name', 'Personal Health Record'))

@section('content')
<div class="max-w-md mx-auto px-4 py-12">
    <div class="bg-card text-card-foreground shadow-md border border-border rounded-lg p-6">
        <h1 class="text-2xl font-bold mb-2">Sign In</h1>
        <p class="text-sm text-muted-foreground mb-6">Sign in to your Personal Health Record.</p>

        @if($errors->has('email'))
            <p class="text-destructive text-sm mb-4">{{ $errors->first('email') }}</p>
        @endif

        <form method="POST" action="/login" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-semibold text-foreground mb-1">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    class="block w-full px-3 py-2 bg-muted border border-input rounded-md text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-ring transition-colors"
                >
            </div>

            <div>
                <label for="password" class="block text-sm font-semibold text-foreground mb-1">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    class="block w-full px-3 py-2 bg-muted border border-input rounded-md text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-ring transition-colors"
                >
            </div>

            <div class="flex items-center">
                <input
                    type="checkbox"
                    id="remember"
                    name="remember"
                    class="h-4 w-4 rounded border-input text-blue-600 focus:ring-ring"
                >
                <label for="remember" class="ml-2 block text-sm text-foreground">Keep me logged in</label>
            </div>

            <button
                type="submit"
                class="w-full bg-primary text-primary-foreground py-2 px-4 rounded-md font-medium hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-ring transition-colors cursor-pointer"
            >
                Sign In
            </button>
        </form>

        {{-- Dev login section (local environment only) --}}
        @if(app()->environment('local'))
        <div class="mt-4">
            <div class="relative mb-4">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-border"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-2 bg-card text-muted-foreground">DEV</span>
                </div>
            </div>
            <form method="POST" action="{{ route('login.dev.by-id') }}">
                @csrf
                <input type="hidden" name="user_id" value="1">
                <button
                    type="submit"
                    class="w-full bg-amber-600 text-white py-2 px-4 rounded-md font-medium hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 transition-colors cursor-pointer text-sm"
                >
                    Dev Login as UID=1
                </button>
            </form>
        </div>
        @endif
    </div>
</div>
@endsection
