<?php

namespace App\Services\Delivery;

use App\Models\Order;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\User;

final class OrderActions
{
    public static function allowed(Staff|User $actor, Order $order): array
    {
        if (! $actor->isActive()) {
            return [];
        }
        $next = ['created' => ['cancelled'], 'assigned' => ['picked_up', 'cancelled'], 'picked_up' => ['out_for_delivery', 'failed'], 'out_for_delivery' => ['delivered', 'failed'], 'failed' => ['out_for_delivery', 'office_pending']][$order->status] ?? [];
        if ($actor instanceof User) {
            return $actor->store?->id === $order->store_id ? array_values(array_intersect($next, ['cancelled'])) : [];
        }
        $actions = $actor->hasRole('captain') && (int) $order->captain_id === (int) $actor->id ? array_values(array_diff($next, ['cancelled'])) : [];
        if ($actor->hasAnyRole(['super_admin', 'operations'])) {
            $actions = array_merge($actions, array_intersect($next, ['cancelled']));
            if ($order->status === 'office_pending') {
                $actions[] = 'office_received';
            }
        }
        if ($actor->hasRole('captain') && (int) $order->returnTask?->captain_id === (int) $actor->id) {
            $actions = array_merge($actions, ['return_assigned' => ['return_picked_up'], 'return_picked_up' => ['return_transit'], 'return_transit' => ['returned']][$order->status] ?? []);
        }

        return array_values(array_unique($actions));
    }
}
