{{-- مواد العميل المستلمة: اثبات استلام بلا قيمة، فهي ملك العميل. --}}
@if ($materials->isNotEmpty())
    <div class="notes" style="margin-top: 4mm;">
        <div class="label">{{ __('Customer Materials') }}</div>
        <div>{{ __('Received from the customer to provide the service on them; they remain the customer\'s property.') }}</div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th>{{ __('Item') }}</th>
                <th class="text-end" style="width: 12%;">{{ __('Quantity') }}</th>
                <th class="text-end" style="width: 14%;">{{ __('Weight') }} ({{ __('kg') }})</th>
                <th style="width: 14%;">{{ __('Received Date') }}</th>
                <th style="width: 24%;">{{ __('Notes') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($materials as $material)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $material->description }}</td>
                    <td class="text-end">{{ number_format((float) $material->quantity, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $material->weight, 3) }}</td>
                    <td>{{ $material->received_date?->toDateString() ?? '-' }}</td>
                    <td>{{ $material->notes }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
