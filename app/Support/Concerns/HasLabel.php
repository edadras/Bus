<?php

namespace App\Support\Concerns;

/**
 * Gives a backed enum a translated, human readable label plus helpers used by
 * the API resources and the admin UI (select boxes, badges, filters).
 */
trait HasLabel
{
    public function label(): string
    {
        return __('enums.'.static::translationKey().'.'.$this->value);
    }

    /** Bootstrap-ish colour token consumed by the admin design system. */
    public function color(): string
    {
        return 'neutral';
    }

    public static function translationKey(): string
    {
        return strtolower(class_basename(static::class));
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, static::cases());
    }

    /** @return array<int, array{value: string, label: string, color: string}> */
    public static function options(): array
    {
        return array_map(static fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'color' => $case->color(),
        ], static::cases());
    }
}
