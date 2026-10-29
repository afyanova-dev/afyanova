<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Single-Actor Approval Limits
    |--------------------------------------------------------------------------
    |
    | The procurement domain assumes a real purchasing department: a purchase
    | order is created by one person, approved by a SECOND, and issued by a
    | THIRD (19-procurement-domain.md §24 / §228). A small private hospital
    | cannot staff three people for every order, so a money limit stands in for
    | the missing people.
    |
    | That limit is NOT a deployment setting. A dispensary, a health centre and
    | a hospital buy at completely different scales, and a laboratory's reagent
    | order is not the same size as the pharmacy's. The operator-facing values
    | therefore live per facility / per department in `pcr_approval_policies`,
    | set with `procurement:configure-approval-limit` (and, when the Procurement
    | screens land, in Settings).
    |
    | Resolution order for an order:
    |
    |   1. the DEPARTMENT policy row  (facility + department)
    |   2. the FACILITY policy row    (facility, department = NULL)
    |   3. `facility_type_defaults` below — the day-one proposal only
    |   4. `0` — off, so the different-actor guard applies unchanged
    |
    | So these numbers are a starting point, never a cap on what an operator may
    | set. A limit of `0` means "never allow one person to approve their own
    | order". Amounts are money in the order currency (TZS), scale 2.
    |
    */
    'approval' => [
        'facility_type_defaults' => [
            'DISPENSARY' => '500000',
            'HEALTH_CENTRE' => '1000000',
            'HOSPITAL' => '3000000',
            'SPECIALIZED_HOSPITAL' => '5000000',
        ],
    ],

];
