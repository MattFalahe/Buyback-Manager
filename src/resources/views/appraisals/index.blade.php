@extends('web::layouts.grids.12')

@section('title', 'My Appraisals')
@section('page_header', 'My Appraisals')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=5">
@endpush

@section('content')
<div class="buyback-manager-wrapper">
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">Appraisals you have generated</h3>
            <div class="card-tools">
                <a href="{{ route('buyback.appraisal.index') }}" class="btn btn-sm btn-bb-primary">
                    <i class="fas fa-calculator"></i> New appraisal
                </a>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Each appraisal carries a single-use key. Put the key in your contract's Description so
                we can match the contract to its quote.
            </p>

            <div class="table-responsive">
                <table class="table table-bb-styled table-compact">
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Corporation</th>
                            <th class="text-right">Quoted</th>
                            <th class="text-center">Items</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($appraisals as $appraisal)
                            <tr>
                                <td>
                                    <a href="{{ route('buyback-manager.public.appraisal', ['ticker' => $appraisal->corporation->ticker ?? '-', 'key' => $appraisal->public_id]) }}">
                                        <code>{{ $appraisal->public_id }}</code>
                                    </a>
                                </td>
                                <td>{{ $appraisal->corporation->name ?? ('#' . $appraisal->corporation_id) }}</td>
                                <td class="text-right">{{ number_format((float) $appraisal->total_buyback_value, 2) }} ISK</td>
                                <td class="text-center">{{ $appraisal->items()->count() }}</td>
                                <td>
                                    @if($appraisal->isClaimed())
                                        <span class="label label-success">Used</span>
                                    @else
                                        <span class="label label-default">Unused</span>
                                    @endif
                                </td>
                                <td>{{ $appraisal->created_at?->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted">
                                    You have not run any appraisals yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($appraisals->hasPages())
            <div class="card-footer">{{ $appraisals->links() }}</div>
        @endif
    </div>
</div>
@endsection
