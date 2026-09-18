<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Playground') &middot; MultiVendor Hub API</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #f4f5f7; color: #1a1a2e; }
        header { background: #0f172a; color: #fff; padding: 14px 24px; display: flex; align-items: center; gap: 24px; }
        header h1 { font-size: 16px; margin: 0; }
        nav a { color: #cbd5e1; text-decoration: none; margin-right: 16px; font-size: 14px; }
        nav a:hover, nav a.active { color: #fff; }
        main { max-width: 1100px; margin: 24px auto; padding: 0 24px 60px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .card h2 { margin-top: 0; font-size: 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .stat { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; }
        .stat .num { font-size: 28px; font-weight: 700; }
        .stat .label { color: #64748b; font-size: 13px; }
        label { display: block; font-size: 13px; color: #475569; margin: 10px 0 4px; }
        input, select, textarea { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
        button { margin-top: 12px; background: #0f172a; color: #fff; border: 0; padding: 9px 16px; border-radius: 6px; font-size: 14px; cursor: pointer; }
        button:hover { background: #1e293b; }
        .btn-alt { background: #2563eb; }
        .btn-danger { background: #dc2626; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #eef2f7; vertical-align: top; }
        th { color: #64748b; font-weight: 600; }
        .flash { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; }
        .flash.ok { background: #ecfdf5; border: 1px solid #10b981; color: #065f46; }
        .flash.err { background: #fef2f2; border: 1px solid #ef4444; color: #991b1b; }
        pre { background: #0f172a; color: #e2e8f0; padding: 14px; border-radius: 8px; overflow: auto; font-size: 12px; max-height: 340px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge.on { background: #dcfce7; color: #166534; }
        .badge.off { background: #fee2e2; color: #991b1b; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .row > div { flex: 1; min-width: 160px; }
        .muted { color: #94a3b8; font-size: 12px; }
        code { background: #f1f5f9; padding: 1px 5px; border-radius: 4px; font-size: 12px; }
    </style>
</head>
<body>
<header>
    <h1>MultiVendor Hub &middot; Laravel API</h1>
    <nav>
        <a href="{{ route('home') }}" class="{{ request()->routeIs('home') ? 'active' : '' }}">Home</a>
        <a href="{{ route('stores') }}" class="{{ request()->routeIs('stores') ? 'active' : '' }}">Stores</a>
        <a href="{{ route('uber') }}" class="{{ request()->routeIs('uber*') ? 'active' : '' }}">Uber Eats</a>
        <a href="{{ route('deliveroo') }}" class="{{ request()->routeIs('deliveroo*') ? 'active' : '' }}">Deliveroo</a>
    </nav>
</header>
<main>
    @if (session('status'))
        <div class="flash {{ session('ok') ? 'ok' : 'err' }}">{{ session('status') }}</div>
    @endif

    @yield('content')

    @if (session('payload'))
        <div class="card">
            <h2>Last response</h2>
            <pre>{{ session('payload') }}</pre>
        </div>
    @endif
</main>
</body>
</html>
