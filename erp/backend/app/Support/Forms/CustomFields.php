<?php

namespace App\Support\Forms;

use App\Models\CustomField;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Validation and clean-up for organization-defined fields. Values are stored
 * in the entity's `custom` JSON column keyed by the field key; keys that are
 * not defined (or inactive) are dropped, so a client cannot store arbitrary data.
 */
final class CustomFields
{
    /** @return Collection<int, CustomField> active fields of the current organization */
    public static function for(string $entity): Collection
    {
        return CustomField::query()->where(['entity' => $entity, 'is_active' => true])->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return array<string, list<mixed>> rules for `custom.<key>` */
    public static function rules(string $entity): array
    {
        $rules = ['custom' => ['nullable', 'array']];
        foreach (self::for($entity) as $field) {
            $base = [$field->is_required ? 'required' : 'nullable'];
            $rules["custom.{$field->key}"] = match ($field->type) {
                'number' => [...$base, 'numeric', 'between:-999999999,999999999'],
                'date' => [...$base, 'date_format:Y-m-d'],
                'select' => [...$base, 'string', Rule::in($field->options ?? [])],
                'checkbox' => [$field->is_required ? 'accepted' : 'nullable', 'boolean'],
                'textarea' => [...$base, 'string', 'max:5000'],
                default => [...$base, 'string', 'max:255'],
            };
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>|null  $input  the validated `custom` array
     * @param  array<string, mixed>|null  $existing  values kept for fields that are now inactive
     * @return array<string, mixed>|null
     */
    public static function clean(string $entity, ?array $input, ?array $existing = null): ?array
    {
        $fields = self::for($entity);
        $active = $fields->pluck('key')->all();
        // Values of fields that were switched off stay, so switching back on loses nothing.
        $values = collect($existing ?? [])->except($active)->all();
        foreach ($fields as $field) {
            $value = $input[$field->key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $values[$field->key] = match ($field->type) {
                'number' => $value + 0,
                'checkbox' => (bool) $value,
                default => is_string($value) ? trim($value) : $value,
            };
        }

        return $values === [] ? null : $values;
    }
}
