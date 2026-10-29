<?php

declare(strict_types=1);

namespace App\Shared\ReadModel;

/**
 * The read-model registry (IMPLEMENTATION-CONTRACTS.md §6.4, ADR-094).
 *
 * A projection table (`{prefix}_rm_{name}` owned by a domain, or `rpt_*` owned
 * by Reporting) is the ONE sanctioned exception to SSoT, so every one is
 * declared here from its module provider's `boot()`:
 *
 *   ReadModelRegistry::declare('patient_billing_summary', [
 *       'table'     => 'bill_rm_patient_billing_summary',
 *       'source'    => 'Billing (authoritative), Encounter, Patient',
 *       'owner'     => 'Billing',
 *       'rebuild'   => 'replay bill.* + enc.* from t0',
 *       'consumers' => ['workspaces/billing', 'workspaces/reception'],
 *   ]);
 *
 * `afyanova:conformance` fails on an `rm_` / `rpt_` table with no declaration
 * (and on a declaration whose `table` does not exist).
 */
final class ReadModelRegistry
{
    /** @var array<string, array<string, mixed>> name => declaration */
    private static array $models = [];

    /**
     * @param  array{table: string, source: string, owner: string, rebuild: string, consumers: list<string>}  $declaration
     */
    public static function declare(string $name, array $declaration): void
    {
        self::$models[$name] = $declaration;
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::$models;
    }

    /** @return list<string> the declared projection table names */
    public static function tables(): array
    {
        return array_values(array_map(
            static fn (array $d): string => (string) $d['table'],
            self::$models,
        ));
    }

    public static function flush(): void
    {
        self::$models = [];
    }
}
