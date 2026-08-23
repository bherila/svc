<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Authorize {{ $client->name }} · SVC</title>
    <style>
        :root { color-scheme: light dark; font-family: ui-sans-serif, system-ui, sans-serif; }
        body { align-items: center; background: #f4f1eb; color: #1f2937; display: flex; justify-content: center; margin: 0; min-height: 100vh; padding: 1.5rem; }
        main { background: #fff; border: 1px solid #d6d3d1; border-radius: 1rem; box-shadow: 0 1rem 3rem rgb(15 23 42 / .08); max-width: 34rem; padding: 2rem; width: 100%; }
        h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
        p { line-height: 1.5; }
        ul { background: #f8fafc; border-radius: .75rem; padding: 1rem 1rem 1rem 2rem; }
        li + li { margin-top: .5rem; }
        .actions { display: flex; gap: .75rem; justify-content: flex-end; margin-top: 1.5rem; }
        button { border: 0; border-radius: .6rem; cursor: pointer; font: inherit; font-weight: 650; padding: .7rem 1rem; }
        .approve { background: #0f766e; color: #fff; }
        .deny { background: #e7e5e4; color: #292524; }
        .identity { color: #57534e; font-size: .9rem; }
        @media (prefers-color-scheme: dark) {
            body { background: #111827; color: #e5e7eb; }
            main { background: #1f2937; border-color: #374151; }
            ul { background: #111827; }
            .deny { background: #374151; color: #e5e7eb; }
            .identity { color: #d6d3d1; }
        }
    </style>
</head>
<body>
<main>
    <h1>Connect {{ $client->name }} to SVC?</h1>
    <p class="identity">Signed in as {{ $user->name }}. This app is requesting:</p>
    <ul>
        @foreach ($scopes as $scope)
            <li>{{ $scope->description }}</li>
        @endforeach
    </ul>
    <p>You can revoke this connection later. SVC permissions and current workspace/project roles still apply to every request.</p>
    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button class="deny" type="submit">Cancel</button>
        </form>
        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button class="approve" type="submit">Authorize</button>
        </form>
    </div>
</main>
</body>
</html>
