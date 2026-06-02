<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sovereign Edge Challenge</title>
    <style>
        body {
            align-items: center;
            background: #0f172a;
            color: #e5e7eb;
            display: flex;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            justify-content: center;
            margin: 0;
            min-height: 100vh;
        }

        main {
            background: #111827;
            border: 1px solid #334155;
            border-radius: 16px;
            box-shadow: 0 24px 80px rgb(0 0 0 / 35%);
            max-width: 460px;
            padding: 32px;
            text-align: center;
        }

        p {
            color: #94a3b8;
            line-height: 1.6;
        }

        button {
            background: #2563eb;
            border: 0;
            border-radius: 10px;
            color: white;
            cursor: pointer;
            font-weight: 600;
            margin-top: 16px;
            padding: 10px 16px;
        }
    </style>
</head>
<body>
    <main>
        <h1>Sovereign Edge Check</h1>
        <p>We are verifying that this browser can receive a short-lived edge proof before continuing.</p>

        <form id="edge-challenge" method="GET" action="{{ $verifyPath }}">
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="return_to" value="{{ $returnTo }}">
            <noscript>
                <p>JavaScript is required for the automatic check. You can continue manually.</p>
                <button type="submit">Continue</button>
            </noscript>
        </form>
    </main>

    <script>
        window.setTimeout(function () {
            document.getElementById('edge-challenge').submit();
        }, 250);
    </script>
</body>
</html>
