{{-- テナントダッシュボード: ビル別カードグリッド --}}
<div class="building-grid">
    @forelse($buildings as $building)
        <a href="{{ route('tenant.properties.show', $building['id']) }}" class="building-card">
            <div class="building-card-name">{{ $building['name'] }}</div>
            <div class="building-stats">
                <div>
                    <div class="building-stat-label">収入</div>
                    <div>
                        <span class="building-stat-value">{{ number_format($building['monthly_income']) }}</span>
                        <span class="building-stat-unit">円</span>
                    </div>
                </div>
                <div>
                    <div class="building-stat-label">入居率</div>
                    <div>
                        <span class="building-stat-value">{{ $building['occupancy_rate'] }}</span>
                        <span class="building-stat-unit">%</span>
                    </div>
                </div>
            </div>
        </a>
    @empty
        <div class="building-empty">
            稼働中のテナントビルが登録されていません。
        </div>
    @endforelse
</div>
