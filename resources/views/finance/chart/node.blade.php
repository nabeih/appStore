<li class="chart-node">
@if($node['children']!==[])<details open><summary>@endif
<div class="chart-account"><a href="{{ route('finance.chart.show',['account'=>$node['account']->id,'branch_id'=>$branchId]) }}"><bdi>{{ $node['account']->code }}</bdi> — {{ $node['account']->name }}</a><span class="sale-badge">{{ $node['account']->is_posting?'تفصيلي':'تجميعي' }}</span><span class="sale-muted">{{ $node['account']->is_active?'فعال':'معطل' }}</span><strong><bdi>{{ \App\Support\Sales\Money::format($node['balance_minor']) }}</bdi> ₪</strong></div>
@if($node['children']!==[])</summary><ul>@foreach($node['children'] as $child)@include('finance.chart.node',['node'=>$child])@endforeach</ul></details>@endif
</li>
