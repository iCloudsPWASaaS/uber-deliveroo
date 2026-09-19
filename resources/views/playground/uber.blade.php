@extends('playground.layout')

@section('title', 'Uber Eats')

@section('content')
    @php
        $config = $store ? collect($store->platforms ?? [])->firstWhere('platform', 'uber_eats') : null;
    @endphp

    <div class="card">
        <h2>Select store</h2>
        <form method="GET" action="{{ route('uber') }}">
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
        <div class="card"><p class="muted">Select a store to use the Uber Eats playground.</p></div>
    @else
        <div class="card">
            <h2>Connection</h2>
            @if ($config && ($config['isConnected'] ?? false))
                <p><span class="badge on">connected</span> store ref <code>{{ $config['storeId'] ?? '' }}</code></p>
                <p class="muted">Webhook URL: <code>{{ config('app.url') }}/api/webhook/uber-eats</code></p>
                @if (! empty($config['webhookSecret']))
                    <p class="muted">Webhook secret: <code>{{ $config['webhookSecret'] }}</code></p>
                @endif
            @else
                <p><span class="badge off">not connected</span></p>
            @endif

            <form method="POST" action="{{ route('uber.connect') }}">
                @csrf
                <input type="hidden" name="store_id" value="{{ (string) $store->_id }}">
                <div class="row">
                    <div><label>Uber store ref</label><input name="store_ref" value="{{ $config['storeId'] ?? '' }}"></div>
                    <div><label>Client ID</label><input name="client_id" value="{{ $config['clientId'] ?? '' }}"></div>
                    <div><label>Client secret</label><input name="client_secret" value="{{ $config['clientSecret'] ?? '' }}"></div>
                    <div><label>Webhook secret (optional)</label><input name="webhook_secret"></div>
                </div>
                <button type="submit">Save connection</button>
            </form>
            <div class="row" style="margin-top:12px">
                <a href="{{ route('uber.activate', ['store' => (string) $store->_id]) }}"><button type="button" class="btn-alt">Activate store via Uber OAuth</button></a>
            </div>
        </div>

        <div class="card">
            <h2>Discover stores</h2>
            <form method="POST" action="{{ route('uber.fetch-stores') }}">
                @csrf
                <input type="hidden" name="store_id" value="{{ (string) $store->_id }}">
                <div class="row">
                    <div><label>Client ID</label><input name="client_id" value="{{ $config['clientId'] ?? '' }}"></div>
                    <div><label>Client secret</label><input name="client_secret" value="{{ $config['clientSecret'] ?? '' }}"></div>
                </div>
                <button type="submit">Fetch Uber stores</button>
            </form>
        </div>

        <div class="card">
            <h2>Store status</h2>
            <form method="POST" action="{{ route('uber.status') }}">
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
            <form method="POST" action="{{ route('uber.menu.pull') }}" style="display:inline">
                @csrf
                <input type="hidden" name="store_id" value="{{ (string) $store->_id }}">
                <button type="submit">Pull menu from Uber</button>
            </form>
            <form method="POST" action="{{ route('uber.menu.push') }}" style="display:inline">
                @csrf
                <input type="hidden" name="store_id" value="{{ (string) $store->_id }}">
                <button type="submit" class="btn-alt">Push local menu to Uber</button>
            </form>
            <form method="POST" action="{{ route('uber.menu.item.save') }}" style="margin-top:10px;padding:8px;border:1px dashed #ccc;border-radius:6px">
                @csrf
                <input type="hidden" name="store_id" value="{{ (string) $store->_id }}">
                <strong>Add item</strong>
                <div class="row">
                    <div><label>Name</label><input name="name" required></div>
                    <div><label>Category</label><input name="category" placeholder="Mains"></div>
                    <div><label>Price (&pound;)</label><input name="basePrice" type="number" step="0.01" min="0" value="0"></div>
                    <div><label>Description</label><input name="description"></div>
                    <div><label>Available</label><input type="checkbox" name="isAvailable" value="1" checked></div>
                </div>
                <button type="submit">Add item</button>
            </form>

            @foreach ($menuItems as $mi)
                <form method="POST" action="{{ route('uber.menu.item.save') }}" style="margin-top:8px;padding:8px;border-top:1px solid #eee">
                    @csrf
                    <input type="hidden" name="store_id" value="{{ (string) $store->_id }}">
                    <input type="hidden" name="item_id" value="{{ (string) $mi->_id }}">
                    <div class="row">
                        <div><input name="name" value="{{ $mi->name }}" title="name"></div>
                        <div><input name="category" value="{{ $mi->category }}" title="category" style="max-width:130px"></div>
                        <div><input name="basePrice" type="number" step="0.01" min="0" value="{{ number_format(($mi->basePrice ?? 0) / 100, 2, '.', '') }}" title="price (£)" style="max-width:90px"></div>
                        <div><input name="description" value="{{ $mi->description }}" title="description" placeholder="description"></div>
                        <div style="display:flex;align-items:center"><label style="margin:0 6px 0 0">Avail.</label><input type="checkbox" name="isAvailable" value="1" {{ $mi->isAvailable ? 'checked' : '' }}></div>
                        <div style="display:flex;gap:6px">
                            <button type="submit">Save</button>
                            <button type="submit" formaction="{{ route('uber.menu.item.delete') }}" onclick="return confirm('Delete \'{{ addslashes($mi->name) }}\'?')">Del</button>
                        </div>
                    </div>
                    @if ($mi->externalId)<div style="font-size:11px;color:#999">Uber id: <code>{{ $mi->externalId }}</code></div>@endif
                </form>
            @endforeach
        </div>

        <div class="card">
            <h2>Order actions</h2>
            <form method="POST" action="{{ route('uber.order') }}">
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
                            <option value="deny">deny</option>
                            <option value="preparing">preparing</option>
                            <option value="ready">ready</option>
                            <option value="cancel">cancel</option>
                        </select>
                    </div>
                    <div><label>Reason</label><input name="reason"></div>
                </div>
                <button type="submit">Send order action</button>
            </form>
            <p class="muted">Tip: paste a raw Uber order UUID via the API route if the order is not stored locally.</p>
        </div>

        <div class="card">
            <h2>Recent Uber orders</h2>
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
                    <tr><td colspan="4" class="muted">No Uber orders.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
@endsection
