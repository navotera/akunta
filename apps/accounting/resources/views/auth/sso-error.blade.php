<!doctype html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Login Akunta gagal</title>
        <style>
            :root { color-scheme: dark; font-family: system-ui, sans-serif; }
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #111827; color: #f9fafb; }
            main { width: min(32rem, calc(100% - 2rem)); box-sizing: border-box; padding: 2rem; border: 1px solid #374151; border-radius: .75rem; background: #1f2937; text-align: center; }
            p { color: #d1d5db; line-height: 1.5; }
            a { display: inline-block; margin-top: 1rem; padding: .7rem 1rem; border-radius: .4rem; background: #2563eb; color: white; text-decoration: none; font-weight: 600; }
        </style>
    </head>
    <body>
        <main>
            <h1>Login Akunta gagal</h1>
            <p>{{ $message }}</p>
            <a href="{{ $retryUrl }}">Coba lagi dengan Ecopa</a>
        </main>
    </body>
</html>
