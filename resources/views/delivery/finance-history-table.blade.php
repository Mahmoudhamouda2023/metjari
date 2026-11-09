<div class="table-wrap"><table class="data-table">
    <thead><tr><th>نوع العملية</th><th>الاسم</th><th>القيمة ₪</th><th>المرجع</th><th>التاريخ</th><th>الحالة</th></tr></thead>
    <tbody>
    @forelse($history as $row)
        <tr>
            <td>{{ ['settlement' => 'تسوية متجر', 'captain' => 'صرف كابتن', 'remittance' => 'توريد كابتن'][$row->kind] }}</td>
            <td>{{ $row->beneficiary }}</td>
            <td>{{ \App\Services\Delivery\Money::show($row->amount_minor) }}</td>
            <td><a class="text-link" href="{{ route('admin.finance.receipt', ['kind' => $row->kind, 'id' => $row->id]) }}"><bdi>{{ $row->reference ?: '#'.$row->id }}</bdi></a></td>
            <td>{{ \Illuminate\Support\Carbon::parse($row->created_at)->timezone('Asia/Hebron')->format('Y/m/d H:i') }}</td>
            <td>{{ ['pending' => 'بانتظار الاستلام', 'approved' => 'مسجلة', 'rejected' => 'مرفوضة', 'voided' => 'معكوسة'][$row->status] }}</td>
        </tr>
    @empty
        <tr><td colspan="6">لا توجد عمليات مسجلة تطابق البحث.</td></tr>
    @endforelse
    </tbody>
</table></div>
