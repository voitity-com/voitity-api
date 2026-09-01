<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class NotReservedProfileAlias implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $alias = Str::lower(trim((string) $value));
        $restrictedAliases = collect(config('public-profiles.restricted_aliases', []))
            ->map(static fn (mixed $restrictedAlias): string => Str::lower(trim((string) $restrictedAlias)));

        if ($restrictedAliases->contains($alias)) {
            $fail('El alias seleccionado está reservado por Bigmelo. Elige uno diferente.');
        }
    }
}
