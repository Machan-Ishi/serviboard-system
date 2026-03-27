<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Error - FinancialSM</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0d1117;
            color: #c9d1d9;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .error-container {
            text-align: center;
            max-width: 600px;
            padding: 2rem;
        }
        .error-code {
            font-size: 6rem;
            font-weight: bold;
            color: #f85149;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        .error-title {
            font-size: 2rem;
            margin: 1rem 0;
            color: #c9d1d9;
        }
        .error-message {
            font-size: 1.1rem;
            color: #8b949e;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .error-actions {
            margin-top: 2rem;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #58a6ff;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin: 0 0.5rem;
            transition: background 0.2s ease;
        }
        .btn:hover {
            background: #79c0ff;
        }
        .btn.secondary {
            background: #30363d;
            color: #c9d1d9;
        }
        .btn.secondary:hover {
            background: #484f58;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1 class="error-code">500</h1>
        <h2 class="error-title">Internal Server Error</h2>
        <p class="error-message">
            Something went wrong on our end. We're working to fix this issue.
            Please try again in a few moments.
        </p>
        <div class="error-actions">
            <a href="javascript:history.back()" class="btn secondary">Go Back</a>
            <a href="/FinancialSM/" class="btn">Return Home</a>
        </div>
    </div>
</body>
</html>