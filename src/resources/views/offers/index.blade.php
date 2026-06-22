@extends('web::layouts.grids.12')

@section('title', 'Buyback Offers')
@section('page_header', 'My Buyback Offers')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=3">
@endpush

@section('content')
<div class="buyback-manager-wrapper">

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fa fa-tag"></i>
                @if($scope === 'corp')
                    All Corporation Offers
                @else
                    Your Buyback Offers
                @endif
            </h3>
            <div class="card-tools">
                @if($isAdmin)
                    @if($scope === 'corp')
                        <a href="{{ route('buyback-manager.offers.index') }}" class="btn btn-xs btn-bb-secondary">
                            <i class="fa fa-user"></i> My offers
                        </a>
                    @else
                        <a href="{{ route('buyback-manager.offers.index', ['scope' => 'corp']) }}" class="btn btn-xs btn-bb-secondary">
                            <i class="fa fa-users"></i> All corp offers
                        </a>
                    @endif
                @endif
                <a href="{{ route('buyback.appraisal.index') }}" class="btn btn-xs btn-bb-primary">
                    <i class="fa fa-plus"></i> New appraisal
                </a>
            </div>
        </div>
        <div class="card-body">
            @if($offers->total() === 0)
                <p class="text-muted" style="font-style:italic;">
                    No offers yet. Head to the
                    <a href="{{ route('buyback.appraisal.index') }}">Appraisal page</a>, paste items, then publish the result.
                </p>
            @else
                <table class="table table-bb-styled">
                    <thead>
                        <tr>
                            <th>Offer</th>
                            <th>Corporation</th>
                            <th>Issuer</th>
                            <th>Send to</th>
                            <th>Status</th>
                            <th class="text-right">Value</th>
                            <th>Expires</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($offers as $offer)
                            <tr>
                                <td>
                                    <a href="{{ route('buyback-manager.offers.show', $offer->public_id) }}">
                                        <code>{{ $offer->public_id }}</code>
                                    </a>
                                </td>
                                <td>{{ $offer->corporation->name ?? 'Unknown' }}</td>
                                <td>{{ $offer->issuer->name ?? '#' . $offer->issuer_character_id }}</td>
                                <td>
                                    @php
                                        $tt = $offer->target_type ?? 'my_corp';
                                        $ttBadge = match($tt) { 'player' => 'warning', 'corp' => 'info', default => 'default' };
                                    @endphp
                                    <span class="label label-{{ $ttBadge }}" title="{{ $offer->sendToLabel() }}">{{ $offer->sendToLabel() }}</span>
                                </td>
                                <td>
                                    @php
                                        $statusClass = match($offer->status) {
                                            'pending' => 'warning',
                                            'matched' => 'info',
                                            'expired' => 'default',
                                            'rejected' => 'danger',
                                            'cancelled' => 'default',
                                            default => 'default',
                                        };
                                    @endphp
                                    <span class="label label-{{ $statusClass }}">{{ ucfirst($offer->status) }}</span>
                                </td>
                                <td class="text-right">{{ number_format((float) $offer->total_buyback_value, 2) }} ISK</td>
                                <td>{{ optional($offer->expires_at)->toDateTimeString() ?: '—' }}</td>
                                <td>{{ $offer->created_at->toDateTimeString() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                {{ $offers->links() }}
            @endif
        </div>
    </div>

</div>
@endsection
