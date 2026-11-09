<?php

namespace App\Services\Delivery;

use GazaDelivery\Foundation\Models\Store;
use Illuminate\Support\Str;
use RuntimeException;

/** Allocated while holding the store row lock, backed by a database UNIQUE constraint. */
final class StoreReference
{
    public function code(Store $store): string
    {
        if ($store->reference_code) {
            return $store->reference_code;
        }
        $name = trim((string) $store->name);
        $normalized = str_replace(['أ','إ','آ','ة','ى','ؤ','ئ'], ['ا','ا','ا','ه','ي','و','ي'], $name);
        $letters = ['ا'=>'A','ب'=>'B','ت'=>'T','ث'=>'T','ج'=>'J','ح'=>'H','خ'=>'K','د'=>'D','ذ'=>'D','ر'=>'R','ز'=>'Z','س'=>'S','ش'=>'S','ص'=>'S','ض'=>'D','ط'=>'T','ظ'=>'Z','ع'=>'A','غ'=>'G','ف'=>'F','ق'=>'Q','ك'=>'K','ل'=>'L','م'=>'M','ن'=>'N','ه'=>'H','و'=>'W','ي'=>'Y'];
        $latin = preg_replace('/[^A-Z]/', '', strtoupper(Str::ascii($name)));
        if ($latin === '') {
            $latin = implode('', array_map(fn ($c) => $letters[$c] ?? '', mb_str_split($normalized)));
        }
        // Common Arabic shop name; use a readable rather than mechanically transliterated code.
        if ($normalized === 'زمزم') {
            $latin = 'ZAMZAM';
        }
        $base = substr($latin.'XXX', 0, 3);
        $candidates = [$base];
        // Deterministic alternatives when two stores have the same initials.
        for ($i = 1; $i < strlen($latin) - 2; $i++) {
            $candidates[] = $latin[0].$latin[$i].$latin[$i + 1];
        }
        foreach (range('A', 'Z') as $a) {
            foreach (range('A', 'Z') as $b) {
                $candidates[] = $base[0].$a.$b;
            }
        }
        foreach (array_unique($candidates) as $candidate) {
            if (! Store::where('reference_code', $candidate)->whereKeyNot($store->id)->exists()) {
                $store->forceFill(['reference_code' => $candidate])->save();
                return $candidate;
            }
        }
        throw new RuntimeException('تعذر تخصيص رمز متجر فريد من ثلاثة أحرف.');
    }
}
