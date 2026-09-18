@extends('playground.layout')

@section('title', 'Deliveroo')

@section('content')
    @php
        $config = $store ? collect($store->platforms ?? [])->firstWhere('platform', 'deliveroo') : null;
    @endphp

    <div class="card">
        <h2>Select store</h2>
        <form method="GET" action="{{ route('deliveroo') }}">
            <div class="row">
                <div>
                    <label>Store</label>
                    <select name="store" onchange="this.form.submit()">
                        <option value="">-- choose a store --</option>
                        @foreach ($stores as $option)
                            <option value="{{ (string) $option->_id }}" @selected($store && (string) $store->_id === (string) $option->_id)>
                                {{ $option->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </form>
    </div>

    @if (! $store)
        <div class="card"><p class="muted">Select a store to use the Deliveroo playground.</p></div>
    @else
        <div class="card">
            <h2>Connection</h2>
            @if ($config && ($config['isConnected'] ?? false))
                <p><span class="badge on">connected</span> brand <code>{{ $config['brandId'] ?? '' }}</code> site <code>{{ $config['storeId'] ?? '' }}</code></p>
                <p class="muted">Webhook URL: <code>{{ config('app.url') }}/api/webhook/deliveroo</code></p>
                @if (! empty($config['webhookSecret']))
                    <p class="muted">Webhook secret: <code>{{ $config['webhookSecret'] }}</code></p>
                @endif
            @else
                <p><span class="badge off">not connected</span></p>
            @endif

            <form method="POST" action="{{ route('deliveroo.connect') }}">
                @csrf
                <input type="hidden" name="store_id" value="{{ (string) $store->_id }}">
                <div class="row">
                    <div><label>Brand ID</label><input name="brand_id" value="{{ $config['brandId'] ?? '' }}"></div>
                    <div><label>Site ref</label><input name="site_ref" value="{{ $config['storeId'] ?? '' }}"></div>
                    <div><label>Client ID</label><input name="client_id" value="{{ $config['clientId'] ?? '' }}"></div>
                    <div><label>Client secret</label><input name="client_secret" value="{{ $config['clientSecret'] ?? '' }}"></div>
                </div>
                <div class="row">
                    <div><label>Webhook secret (optional)</label><input name="webhook_secret"></div>
                </div>
                <button type="submit">Save connection</button>
            </form>
        </div>

        <div class="card">
            <h2>Discover sites</h2>
            <form method="POST" action="{{ route('deliveroo.fetch-sites') }}">
                @csrf
                <input type="hidden" name="store_id" value="{{ (string) $store->_id }}">
                <div class="row">
                    <div><label>Client ID</label><input name="client_id" value="{{ $config['clientId'] ?? '' }}"></div>
                    <div><label>Client secret</label><input name="client_secret" value="{{ $config['clientSecret'] ?? '' }}"></div>
                    <div><label>Brand ID</label><input name="brand_id" value="{{ $config['brandId'] ?? '' }}"></div>
                </div>
                <button type="submit">Fetch Deliveroo sites</button>
            </form>
        </div>

        <div class="card">
            <h2>Site status</h2>
            <form method="POST" action="{{ route('deliveroo.status') }}">
                @csrf
                <input type="hidden" name="store_id" value="{{ (string) $store->_id }}">
                <div class="row">
                    <div><label>Online</label><select name="is_online"><option value="1">yes</option><option value="0">no</option></select></div>
                    <div><label>Busy mode</label><select name="busy_mode"><option value="0">no</option><option value="1">yes</option></select></div>
                    <div><label>Pause new orders</label><select name="pause_new_orders"><option value="0">no</option><option value="1">yes</option></select></div>
                </div>
                <button type="submit">Push status</button>
            </form>
        </div>

        <div class="card">
            <h2>Menu ({{ $menuItems->count() }} local items)</h2>
            <form method="POST" action="{{ route('deliveroo.menu.pull') }}" style="display:inline">
                @csrf
                <input type="hidden" name="store_id" value="{{ (string) $store->_id }}">
                <button type="submit">Pull menu from Deliveroo</button>
            </form>
            <form method="POST" action="{{ route('deliveroo.menu.push') }}" style="display:inline">
                @csrf
                <input type="hidden" name="store_id" value="{{ (string) $store->_id }}">
                <button type="submit" class="btn-alt">Push local menu to Deliveroo</button>
            </form>
        </div>

        <div class="card">
            <h2>Order actions</h2>
            <form method="POST" action="{{ route('deliveroo.order') }}">
                @csrf
                <input type="hidden" name="store_id" value="{{ (string) $store->_id }}">
                <div class="row">
                    <div>
                        <label>Order</label>
                        <select name="order_id">
                            <option value="">-- use a local order --</option>
                            @foreach ($orders as $order)
                                <option value="{{ (string) $order->_id }}">{{ $order->orderNumber }} ({{ $order->status }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>Action</label>
                        <select name="action">
                            <option value="accept">accept</option>
                            <option value="reject">reject</option>
                            <option value="preparing">preparing</option>
                            <option value="ready">ready</option>
                        </select>
                    </div>
                </div>
                <button type="submit">Send order action</button>
            </form>
        </div>

        <div class="card">
            <h2>Recent Deliveroo orders</h2>
            <table>
                <thead><tr><th>#</th><th>Status</th><th>Total</th><th>Created</th></tr></thead>
                <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td>{{ $order->orderNumber }}</td>
                        <td>{{ $order->status }}</td>
                        <td>{{ number_format(($order->total ?? 0) / 100, 2) }} {{ $order->currency }}</td>
                        <td>{{ optional($order->createdAt)->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No Deliveroo orders.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
@endsection
