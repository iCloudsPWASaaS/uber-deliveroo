@extends('playground.layout')

@section('title', 'Stores')

@section('content')
    <div class="card">
        <h2>Create store</h2>
        <form method="POST" action="{{ route('stores.create') }}">
            @csrf
            <div class="row">
                <div><label>Name</label><input name="name" required></div>
                <div><label>Address</label><input name="address" required></div>
                <div><label>City</label><input name="city" required></div>
            </div>
            <div class="row">
                <div><label>Postcode</label><input name="postcode"></div>
                <div><label>Phone</label><input name="phone"></div>
                <div><label>Email</label><input type="email" name="email"></div>
            </div>
            <button type="submit">Create store</button>
        </form>
    </div>

    <div class="card">
        <h2>Stores</h2>
        <table>
            <thead><tr><th>Name</th><th>City</th><th>Platforms</th><th>Online</th><th></th></tr></thead>
            <tbody>
            @forelse ($stores as $store)
                @php
                    $connected = collect($store->platforms ?? [])->filter(fn ($p) => $p['isConnected'] ?? false)->pluck('platform');
                @endphp
                <tr>
                    <td>{{ $store->name }}</td>
                    <td>{{ $store->city }}</td>
                    <td>
                        @forelse ($connected as $platform)
                            <span class="badge on">{{ $platform }}</span>
                        @empty
                            <span class="muted">none</span>
                        @endforelse
                    </td>
                    <td><span class="badge {{ $store->isOnline ? 'on' : 'off' }}">{{ $store->isOnline ? 'online' : 'offline' }}</span></td>
                    <td>
                        <a href="{{ route('stores', ['store' => (string) $store->_id]) }}">View</a> &middot;
                        <a href="{{ route('uber', ['store' => (string) $store->_id]) }}">Uber</a> &middot;
                        <a href="{{ route('deliveroo', ['store' => (string) $store->_id]) }}">Deliveroo</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No stores yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($selected)
        <div class="card">
            <h2>{{ $selected->name }} &middot; menu items ({{ $items->count() }})</h2>
            <table>
                <thead><tr><th>Name</th><th>Category</th><th>Price</th><th>Available</th></tr></thead>
                <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td>{{ $item->name }}</td>
                        <td>{{ $item->category }}</td>
                        <td>{{ number_format(($item->basePrice ?? 0) / 100, 2) }} {{ $item->currency ?? 'GBP' }}</td>
                        <td>{{ $item->isAvailable ? 'yes' : 'no' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No menu items.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>{{ $selected->name }} &middot; recent orders ({{ $orders->count() }})</h2>
            <table>
                <thead><tr><th>#</th><th>Platform</th><th>Status</th><th>Total</th></tr></thead>
                <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td>{{ $order->orderNumber }}</td>
                        <td>{{ $order->platform }}</td>
                        <td>{{ $order->status }}</td>
                        <td>{{ number_format(($order->total ?? 0) / 100, 2) }} {{ $order->currency }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No orders.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
@endsection
