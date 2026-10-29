<?php

declare(strict_types=1);

namespace App\Shared\Bus\Middleware;

use App\Shared\Bus\AuthorizesAgainst;
use App\Shared\Bus\CommandBus;
use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use RuntimeException;

/**
 * Bus step 2 — the authoritative authorization check
 * (IMPLEMENTATION-CONTRACTS.md §5, §5.1, §12).
 *
 * `AuthorizesAgainst` command:
 *   - `subjectId() !== null` → load the target aggregate via the owning
 *     repository (RLS-scoped), call `{Aggregate}Policy::{ability}($actor,
 *     $aggregate, $command)`;
 *   - `subjectId() === null` → class-level check on the aggregate class.
 * The check is NEVER skipped — a dispatch with no principal makes
 * `Gate::authorize` fail loudly. A command without `AuthorizesAgainst`
 * (a create/search the Form Request coarse-checked, or an event-originated
 * system reaction — §7.3) gets no bus-level policy call.
 *
 * DERIVED: §5.1 does not say how the generic bus discovers "the owning
 * repository". It reflects the command's handler constructor for the parameter
 * typed `…Repository` (the §5 handler skeleton always injects one) and derives
 * the aggregate class from that name per the §2.1 module layout.
 */
final class AuthorizeCommand
{
    public function __construct(
        private readonly Gate $gate,
        private readonly Container $container,
    ) {}

    public function handle(object $command, Closure $next): mixed
    {
        if (! $command instanceof AuthorizesAgainst) {
            return $next($command);
        }

        $ability = $command->policyAbility();
        $subjectId = $command->subjectId();
        $repositoryType = $this->ownerRepositoryType($command);

        if ($subjectId === null) {
            $this->gate->authorize($ability, [$this->aggregateClass($repositoryType), $command]);

            return $next($command);
        }

        $subject = $this->container->make($repositoryType)->getById($subjectId); // RLS-scoped; throws NotFound{Aggregate}
        $this->gate->authorize($ability, [$subject, $command]);

        return $next($command);
    }

    /** @return class-string the `…Repository` the command's handler injects */
    private function ownerRepositoryType(AuthorizesAgainst $command): string
    {
        $handler = CommandBus::handlerFor($command::class)
            ?? throw new RuntimeException('No handler registered for '.$command::class.'.');

        foreach ((new ReflectionClass($handler))->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()
                && str_ends_with(class_basename($type->getName()), 'Repository')) {
                /** @var class-string */
                return $type->getName();
            }
        }

        throw new RuntimeException(
            $command::class." implements AuthorizesAgainst but its handler injects no '…Repository' the bus can load the subject through (§5.1)."
        );
    }

    /** `…\{X}Repository` → `…\Model\{X}` or the sibling `…\{X}` (§2.1). */
    private function aggregateClass(string $repositoryType): string
    {
        $namespace = trim(implode('\\', array_slice(explode('\\', $repositoryType), 0, -1)), '\\');
        $aggregate = substr(class_basename($repositoryType), 0, -strlen('Repository'));

        $candidates = [
            "{$namespace}\\Model\\{$aggregate}",
            "{$namespace}\\{$aggregate}",
            (string) preg_replace('/\\\\Domain$/', '\\Domain\\Model', $namespace)."\\{$aggregate}",
        ];

        // Modules that group ports under `Domain\Repository\` (models alongside
        // in `Domain\Model\`): drop the trailing Repository segment to reach
        // the domain root before appending `Model\{Aggregate}`.
        $segments = explode('\\', $repositoryType);
        array_pop($segments);
        if (end($segments) === 'Repository') {
            array_pop($segments);
            $candidates[] = implode('\\', $segments)."\\Model\\{$aggregate}";
        }

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException("Cannot resolve the aggregate class for repository [{$repositoryType}] (§5.1).");
    }
}
