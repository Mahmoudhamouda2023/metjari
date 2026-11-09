<?php

namespace App\Services\Delivery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * Server-issued and immutable operation identifiers.
 *
 * The database-assigned primary key is unique across every store within each
 * operation table. Each table has a different prefix, so the combined format
 * cannot overlap with another operation type. Call only after saving the row,
 * inside the same database transaction as the business operation.
 */
final class OperationReference
{
    public static function issue(string $type, Model $record, ?Carbon $eventAt = null): string
    {
        $prefix = match ($type) {
            'delivery' => 'DLV',
            'remittance' => 'REM',
            'settlement' => 'STL',
            'captain_payout' => 'PAY',
            default => throw new InvalidArgumentException('Unknown reference type.'),
        };
        if (! $record->exists || ! $record->getKey() || ! $record->created_at) {
            throw new LogicException('An operation must be saved before issuing its reference.');
        }

        return $prefix.'-'.($eventAt ?: $record->created_at)->copy()->timezone('Asia/Hebron')->format('dmY').'-'
            .str_pad((string) $record->getKey(), 6, '0', STR_PAD_LEFT);
    }
}
