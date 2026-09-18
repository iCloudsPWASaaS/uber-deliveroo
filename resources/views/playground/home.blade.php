@extends('playground.layout')

@section('title', 'Home')

@section('content')
    <div class="grid">
        <div class="stat"><div class="num">{{ $storeCount }}</div><div class="label">Stores</div></div>
        <div class="stat"><div class="num">{{ $itemCount }}</div><div class="label">Menu items</div></div>
        <div class="stat"><div class="num">{{ $orderCount }}</div><div class="label">Orders</div></div>
    </div>

    <div class="card" style="margin-top:20px">
        <h2>Playgrounds</h2>
        <p class="muted">Open playgrounds for each marketplace integration. No authentication required.</p>
        <p>
            <a href="{{ route('uber') }}"><button class="btn-alt">Uber Eats playground</button></a>
            <a href="{{ route('deliveroo') }}"><button>Deliveroo playground</button></a>
            <a href="{{ route('stores') }}"><button>Manage stores</button></a>
        </p>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Recent stores</h2>
            <table>
                <thead><tr><th>Name</th><th>City</th><th>Online</th></tr></thead>
                <tbody>
                @forelse ($stores as $store)
                    <tr>
                        <td><a href="{{ route('stores', ['store' => (string) $store->_id]) }}">{{ $store->name }}</a></td>
                        <td>{{ $store->city }}</td>
                        <td><span class="badge {{ $store->isOnline ? 'on' : 'off' }}">{{ $store->isOnline ? 'online' : 'offline' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">No stores yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Recent orders</h2>
            <table>
                <thead><tr><th>#</th><th>Platform</th><th>Status</th><th>Total</th></tr></thead>
                <tbody>
                @forelse ($recentOrders as $order)
                    <tr>
                        <td>{{ $order->orderNumber }}</td>
                        <td>{{ $order->platform }}</td>
                        <td>{{ $order->status }}</td>
                        <td>{{ number_format(($order->total ?? 0) / 100, 2) }} {{ $order->currency }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No orders yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
