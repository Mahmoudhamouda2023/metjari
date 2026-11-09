<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\ReturnTask;
use GazaDelivery\Foundation\Enums\StoreStatus;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\Store;
use Illuminate\Http\Request;

final class DashboardController extends Controller
{
    public function admin(Request $request)
    {
        $counts = Store::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return view('dashboard.admin', [
            'account' => $request->user('staff'), 'counts' => $counts,
            'orderCounts' => Order::selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
            'openIssues' => OrderIssue::whereNull('resolved_at')->count(),
            'pendingStores' => Store::query()->with('owner:id,name,phone')->where('status', 'pending')->oldest()->limit(5)->get(),
            'activeStaff' => $request->user('staff')->can('staff.view') ? Staff::query()->where('status', 'active')->count() : null,
        ]);
    }

    public function merchant(Request $request)
    {
        return view('dashboard.merchant', ['account' => $request->user('merchant')->load('store'), 'orderCounts' => Order::where('store_id', $request->user('merchant')->store->id)->selectRaw('status,COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'), 'scheduledCount' => Order::where('store_id', $request->user('merchant')->store->id)->whereNotNull('scheduled_for')->whereNotIn('status', ['delivered', 'returned', 'cancelled'])->count(), 'latestOrders' => Order::where('store_id', $request->user('merchant')->store->id)->latest('id')->limit(5)->get()]);
    }

    public function status(Request $request)
    {
        $account = $request->user('merchant')->load('store');
        if ($account->isActive() && $account->store?->status === StoreStatus::Active) {
            return redirect()->route('merchant.dashboard');
        }

        return view('dashboard.status', compact('account'));
    }

    public function captain(Request $request)
    {
        $returnTasks = ReturnTask::where('captain_id', $request->user('staff')->id)->whereHas('order', fn ($q) => $q->whereIn('status', ['return_assigned', 'return_picked_up', 'return_transit']))->with('order.store')->latest('id')->get();

        return view('dashboard.captain', ['returnTasks' => $returnTasks, 'account' => $request->user('staff')->load('captainProfile'), 'tasks' => Order::where('captain_id', $request->user('staff')->id)->whereIn('status', ['assigned', 'picked_up', 'out_for_delivery', 'failed'])->with('store')->latest('id')->limit(10)->get()]);
    }
}
