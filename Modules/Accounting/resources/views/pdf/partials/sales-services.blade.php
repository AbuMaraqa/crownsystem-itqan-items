{{-- خدمات المستند للعميل: بلا تكلفة الخدمة، فهي معلومة داخلية. --}}
@if ($lines->isNotEmpty())
    <table class="items" style="margin-top: 4mm;">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th>{{ __('Service') }}</th>
                <th style="width: 14%;">{{ __('Pricing Basis') }}</th>
                <th class="text-end" style="width: 10%;">{{ __('Quantity') }}</th>
                <th class="text-end" style="width: 10%;">{{ __('Price') }}</th>
                <th class="text-end" style="width: 10%;">{{ __('Discount') }}</th>
                <th class="text-end" style="width: 12%;">{{ __('Total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                @php $basis = \Modules\Items\Enums\ServicePricingBasis::tryFrom((string) $line->pricing_basis); @endphp
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $line->description }}</td>
                    <td>{{ $basis?->getLabel() ?? '-' }}</td>
                    <td class="text-end">{{ number_format((float) $line->quantity, 2) }} {{ $basis?->unitLabel() }}</td>
                    <td class="text-end">{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $line->discount_amount, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
