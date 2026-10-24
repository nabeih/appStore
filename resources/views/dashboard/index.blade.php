@extends('layouts.app')
@section('title', 'لوحة التحكم')
@section('content')

<!-- ============ لوحة التحكم ============ -->
<div id="screen-dashboard" class="screen active">
    <h1 class="section-title"><i class="fas fa-chart-pie text-primary"></i> لوحة التحكم</h1>
    <p class="section-subtitle">نظرة شاملة على أداء متجرك</p>
    <div class="stats-grid" id="dashboardStats"></div>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-clock text-primary"></i> آخر المبيعات</h3>
            <button class="btn btn-sm btn-outline" onclick="switchScreen('sales-record')">عرض الكل</button>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>العميل</th>
                        <th>المبلغ</th>
                        <th>التاريخ</th>
                    </tr>
                </thead>
                <tbody id="recentSalesTable"></tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-exclamation-triangle text-warning"></i> تنبيهات</h3>
        </div>
        <div id="inventoryAlerts"></div>
    </div>
</div>
@endsection
