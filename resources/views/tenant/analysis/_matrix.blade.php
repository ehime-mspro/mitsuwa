@if($matrix['grandTotal'] === 0)
    <div class="bg-white border border-gray-200 rounded-lg" style="padding:40px; text-align:center; color:#9CA3AF; font-size:14px;">
        {{ $emptyLabel }}
    </div>
@else
    <div class="bg-white border border-gray-200 rounded-lg" style="padding:12px; overflow-x:auto;">
        <table style="border-collapse:collapse; width:100%; min-width:720px; font-size:13px;">
            <thead>
                <tr>
                    <th style="padding:6px 8px; text-align:left; color:#6B7280; font-weight:600; border-bottom:2px solid #E5E7EB; white-space:nowrap;">年＼月</th>
                    @for($m = 1; $m <= 12; $m++)
                        <th style="padding:6px 4px; text-align:center; color:#6B7280; font-weight:600; border-bottom:2px solid #E5E7EB; width:52px;">{{ $m }}</th>
                    @endfor
                    <th style="padding:6px 8px; text-align:center; color:#374151; font-weight:700; border-bottom:2px solid #E5E7EB; border-left:1px solid #E5E7EB; white-space:nowrap;">年計</th>
                </tr>
            </thead>
            <tbody>
                @foreach($matrix['years'] as $y)
                    <tr>
                        <th style="padding:6px 8px; text-align:left; color:#374151; font-weight:700; border-bottom:1px solid #F3F4F6; white-space:nowrap;">{{ $y }}</th>
                        @for($m = 1; $m <= 12; $m++)
                            @php
                                $count   = $matrix['cells'][$y][$m] ?? 0;
                                $ratio   = $matrix['max'] > 0 ? $count / $matrix['max'] : 0;
                                $opacity = $count > 0 ? number_format(0.12 + $ratio * 0.73, 2) : '0';
                            @endphp
                            <td style="padding:6px 4px; text-align:center; border-bottom:1px solid #F3F4F6; background-color:rgba(5,150,105,{{ $opacity }}); color:#111827; font-variant-numeric:tabular-nums;">{{ $count > 0 ? $count : '' }}</td>
                        @endfor
                        <td style="padding:6px 8px; text-align:center; font-weight:700; color:#374151; border-bottom:1px solid #F3F4F6; border-left:1px solid #E5E7EB; font-variant-numeric:tabular-nums;">{{ $matrix['yearTotals'][$y] }}</td>
                    </tr>
                @endforeach
                {{-- 月計 --}}
                <tr>
                    <th style="padding:6px 8px; text-align:left; color:#374151; font-weight:700; border-top:2px solid #E5E7EB;">月計</th>
                    @for($m = 1; $m <= 12; $m++)
                        <td style="padding:6px 4px; text-align:center; font-weight:700; color:#374151; border-top:2px solid #E5E7EB; font-variant-numeric:tabular-nums;">{{ $matrix['monthTotals'][$m] > 0 ? $matrix['monthTotals'][$m] : '' }}</td>
                    @endfor
                    <td style="padding:6px 8px; text-align:center; font-weight:700; color:#047857; border-top:2px solid #E5E7EB; border-left:1px solid #E5E7EB; font-variant-numeric:tabular-nums;">{{ $matrix['grandTotal'] }}</td>
                </tr>
            </tbody>
        </table>
    </div>
@endif
