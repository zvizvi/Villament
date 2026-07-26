<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

/**
 * Factors the record-invariant half of the per-record action payload out into
 * one copy per response.
 *
 * Every field of an action can be a closure (`->label(fn ($record) => …)`,
 * `->color()`, `->icon()`, `->disabled()`, …), so `RecordSerializer` has to
 * evaluate all 18 of them per record — and has no way to know which ones ended
 * up actually depending on the record. In practice almost none do: on a plain
 * `EditAction`/`DeleteAction` pair only `url` differs, and the two inlined
 * icon SVGs alone repeat ~1.4 kB per row.
 *
 * So this does not PREDICT which fields are static; it MEASURES sameness
 * across the records of this response and ships each record only its
 * differences. Nothing here changes what the client renders — it is purely a
 * transport shape.
 *
 * The one thing that must not be inferred is VISIBILITY. Before this pass, a
 * hidden action was simply absent from `record.actions`, and the client keyed
 * off exactly that. Once "absent" also means "identical to the defaults",
 * absence can no longer carry that meaning — hence the explicit `actionNames`
 * list on every record.
 */
final class ActionPayloadCompactor
{
    /**
     * @param  list<array<string, mixed>>  $records  Serialized records; each `actions` entry is the FULL evaluated payload.
     * @return array{records: list<array<string, mixed>>, actionDefaults: array<string, array<string, mixed>>}
     */
    public function compact(array $records): array
    {
        if ($records === []) {
            return ['records' => [], 'actionDefaults' => []];
        }

        $defaults = $this->defaults($records);

        foreach ($records as $index => $record) {
            $actions = $record['actions'] ?? [];

            if (! is_array($actions)) {
                continue;
            }

            // Explicit, and in structure order: the client tests membership of
            // this list, never truthiness of the delta below.
            $records[$index]['actionNames'] = array_keys($actions);

            foreach ($actions as $name => $payload) {
                $records[$index]['actions'][$name] = $this->diff($payload, $defaults[$name] ?? []);
            }
        }

        return ['records' => $records, 'actionDefaults' => $defaults];
    }

    /**
     * The fields that came out identical across every record where the action
     * is VISIBLE. Scoping to the visible subset matters: an action shown on
     * rows 1-10 and hidden on 11-50 must take its defaults from those ten, and
     * an action visible nowhere must not appear here at all.
     *
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array<string, mixed>>
     */
    private function defaults(array $records): array
    {
        /** @var array<string, array<string, mixed>> $candidates */
        $candidates = [];

        /** @var array<string, array<string, string>> $encoded */
        $encoded = [];

        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($records as $record) {
            $actions = $record['actions'] ?? [];

            if (! is_array($actions)) {
                continue;
            }

            foreach ($actions as $name => $payload) {
                if (! is_array($payload)) {
                    continue;
                }

                if (! isset($seen[$name])) {
                    $seen[$name] = true;
                    $candidates[$name] = $payload;
                    $encoded[$name] = array_map($this->encode(...), $payload);

                    continue;
                }

                foreach ($candidates[$name] as $field => $value) {
                    // A field missing from this record's payload can never be
                    // a default — the shapes have to agree exactly.
                    if (! array_key_exists($field, $payload) || $encoded[$name][$field] !== $this->encode($payload[$field])) {
                        unset($candidates[$name][$field], $encoded[$name][$field]);
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * This record's payload minus everything the defaults already carry.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function diff(array $payload, array $defaults): array
    {
        foreach ($defaults as $field => $value) {
            unset($payload[$field]);
        }

        return $payload;
    }

    /**
     * Strict value identity. `iconHtml` is a long string and `extraAttributes`
     * an array, so comparison goes through a canonical encoding rather than
     * `==` — which would happily collapse `[]` against `{}`, or `0` against
     * `'0'`, and ship the wrong default.
     */
    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
