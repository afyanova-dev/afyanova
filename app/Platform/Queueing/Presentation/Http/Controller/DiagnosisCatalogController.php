<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class DiagnosisCatalogController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('clinical.access'), 403, 'You do not have access to clinical workspaces.');
        abort_unless($user->can('clinical.diagnoses.read'), 403, 'You do not have access to diagnosis terminology.');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));

        $diagnoses = DB::table('clin_diagnosis_catalog')
            ->where('active', true)
            ->when($category !== '', static fn ($query) => $query->where('category', $category))
            ->when($search !== '', static function ($query) use ($search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(static fn ($matches) => $matches
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(code) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(aliases) LIKE ?', [$term]));
            })
            ->orderBy('category')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        $categories = DB::table('clin_diagnosis_catalog')
            ->where('active', true)
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();

        return Inertia::render('Clinician/DiagnosisCatalog', [
            'diagnoses' => [
                'data' => $diagnoses->getCollection()->map(static function (object $diagnosis): array {
                    return [
                        'id' => (string) data_get($diagnosis, 'id'),
                        'code' => (string) data_get($diagnosis, 'code'),
                        'codeSystem' => (string) data_get($diagnosis, 'code_system'),
                        'name' => (string) data_get($diagnosis, 'name'),
                        'aliases' => (string) data_get($diagnosis, 'aliases', ''),
                        'category' => (string) data_get($diagnosis, 'category'),
                    ];
                })->values()->all(),
                'total' => $diagnoses->total(),
                'currentPage' => $diagnoses->currentPage(),
                'lastPage' => $diagnoses->lastPage(),
                'perPage' => $diagnoses->perPage(),
            ],
            'categories' => $categories,
            'filters' => [
                'search' => $search,
                'category' => $category,
            ],
        ]);
    }
}
