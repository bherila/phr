<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm Login</title>
</head>
<body style="font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; background: #f8fafc; color: #0f172a;">
    <main style="min-height: 100vh; display: grid; place-items: center; padding: 24px;">
        <section style="width: min(100%, 420px); background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08); text-align: center;">
            <h1 style="font-size: 24px; margin: 0 0 12px;">Confirm Your Login</h1>
            <p style="margin: 0; color: #475569;">You are about to sign in as <strong>{{ $userEmail }}</strong>.</p>
            <p style="margin: 8px 0 0; color: #475569;">Click the button below to complete your login.</p>

            <form action="{{ $submitRoute }}" method="POST" style="margin-top: 24px;">
                @csrf
                <button type="submit" style="width: 100%; border: 0; border-radius: 6px; background: #2563eb; color: white; padding: 10px 16px; font-weight: 600; cursor: pointer;">
                    Yes, Sign Me In
                </button>
            </form>

            <a href="{{ $loginUrl }}" style="display: block; margin-top: 16px; color: #475569; font-size: 14px;">Return to login</a>
        </section>
    </main>
</body>
</html>
