{{-- رأس كل صفحة: ترويسة الشركة (Letterhead)، عنوان المستند، ومربع البيانات.
     $docTitle، $party: ['label' => ..., 'name' => ..., 'detail' => ...] او null، $meta: [[عنوان, قيمة], ...]،
     $metaColumns: عدد اعمدة البيانات. --}}
@php
    $letterhead ??= \Modules\Core\Support\Letterhead::data();
    $contacts = array_values(array_filter([
        [$letterhead['phone'] ? 'Tel. '.$letterhead['phone'] : null, $letterhead['email']],
        [$letterhead['fax'] ? 'Fax. '.$letterhead['fax'] : null, $letterhead['website']],
    ], fn (array $row) => filled($row[0]) || filled($row[1])));
@endphp
<htmlpageheader name="doc-header">
    <table class="letterhead">
        <tr>
            <td style="width: 55%; height: 24mm;">
                <div class="company" style="font-size: 18pt; color: #5b6166;">{{ $letterhead['name'] }}</div>
                @if ($contacts)
                    <table class="contacts" align="right">
                        @foreach ($contacts as [$first, $second])
                            <tr>
                                <td>{{ $second }}</td>
                                <td class="{{ filled($first) && filled($second) ? 'sep' : '' }}">{{ $first }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </td>
            <td style="width: 45%; text-align: left;">
                @if ($letterhead['logo'])
                    <img src="{{ $letterhead['logo'] }}" style="max-height: 22mm; max-width: 62mm;" alt="">
                @endif
            </td>
        </tr>
    </table>

    <div class="doc-title">{{ $docTitle ?? '' }}</div>

    @if ($party || $meta)
        <div class="info-box"><table class="info-box">
            <tr>
                @if ($party)
                    <td style="width: 52%;">
                        <div class="party-name">{{ $party['name'] ?? '-' }}</div>
                        <div class="party-label">{{ $party['label'] ?? __('Messrs.') }} @if (filled($party['detail'] ?? null)) &nbsp; ({{ $party['detail'] }}) @endif</div>
                    </td>
                @endif
                <td>
                    <table class="meta">
                        @foreach (array_chunk($meta, $metaColumns) as $row)
                            <tr>
                                @foreach ($row as [$label, $value])
                                    <td class="label">{{ $label }}</td>
                                    <td>{{ filled($value) ? $value : '-' }}</td>
                                @endforeach
                                @for ($i = count($row); $i < $metaColumns; $i++)
                                    <td></td><td></td>
                                @endfor
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        </table></div>
    @endif
</htmlpageheader>
