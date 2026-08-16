<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Authorize {{ $client->name }}</title>
    <style>
        body { background: #f4f7fa; color: #17202a; font-family: system-ui, sans-serif; margin: 0; }
        main { background: white; border: 1px solid #d9e1e8; border-radius: 12px; margin: 8vh auto; max-width: 42rem; padding: 2rem; }
        h1 { margin-top: 0; }
        li { margin: .6rem 0; }
        .warning { background: #fff8db; border-left: 4px solid #c99700; padding: .8rem; }
        .actions { display: flex; gap: .75rem; margin-top: 1.5rem; }
        button { border: 0; border-radius: 6px; cursor: pointer; font: inherit; padding: .7rem 1rem; }
        .approve { background: #176b47; color: white; }
        .deny { background: #e6e9ec; color: #17202a; }
    </style>
</head>
<body>
<main>
    <h1>Authorize {{ $client->name }}</h1>
    <p>This application is requesting access to your personal health record.</p>
    <p class="warning">Only continue if you recognize and trust this application. You can disconnect it later.</p>
    @if ($client->dynamically_registered_at)
        <p class="warning">
            This client registered automatically. After approval, your browser returns to:
            <strong>{{ $request->query('redirect_uri') }}</strong>
        </p>
    @endif
    <h2>Requested permissions</h2>
    <ul>
        @foreach ($scopes as $scope)
            <li>{{ $scope->description }}</li>
        @endforeach
    </ul>
    <div class="actions">
        <form method="post" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button class="approve" type="submit">Authorize</button>
        </form>
        <form method="post" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button class="deny" type="submit">Deny</button>
        </form>
    </div>
</main>
</body>
</html>
