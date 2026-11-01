<?php
// error.php — Friendly error page shown when an unexpected exception occurs.
// Never reveals technical details — those go to logs/error.log only.
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Something went wrong</title>
    <link rel="stylesheet" href="assets/css/bootstrap-icons.css">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .box { background: #fff; border-radius: 16px; padding: 48px 44px; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 8px 32px rgba(0,0,0,0.1); }
        .icon { font-size: 3rem; color: #dc2626; margin-bottom: 16px; }
        h1 { font-size: 1.4rem; font-weight: 700; color: #0f172a; margin-bottom: 10px; }
        p { font-size: 0.9rem; color: #64748b; margin-bottom: 24px; line-height: 1.6; }
        a { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; background: #2563eb; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: 0.9rem; }
        a:hover { background: #1e4d8c; }
    </style>
</head>
<body>
    <div class="box">
        <div class="icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <h1>Something went wrong</h1>
        <p>An unexpected error occurred. The administrator has been notified. Please try again.</p>
        <a href="index.php"><i class="bi bi-arrow-left"></i> Go Back to Login</a>
    </div>
</body>
</html>
