<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Request;

use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

final class SaveLabTestDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageCatalog', LabOrder::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [$this->isMethod('post') ? 'required' : 'prohibited', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'specimen_type' => ['required', 'string', 'max:100'],
            'container_type' => ['required', 'string', 'max:100'],
            'parameters' => ['required', 'array'],
            'parameters.*' => ['array:code,name,value_type,unit,reference_ranges,coded_values'],
            'parameters.*.code' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'parameters.*.name' => ['required', 'string', 'max:200'],
            'parameters.*.value_type' => ['required', Rule::in(['NUMERIC', 'CODED', 'TEXT'])],
            'parameters.*.unit' => ['nullable', 'string', 'max:100'],
            'parameters.*.reference_ranges' => ['nullable', 'array:low,high,text,source'],
            'parameters.*.reference_ranges.low' => ['nullable', 'numeric'],
            'parameters.*.reference_ranges.high' => ['nullable', 'numeric'],
            'parameters.*.reference_ranges.text' => ['nullable', 'string', 'max:255'],
            'parameters.*.reference_ranges.source' => ['nullable', 'string', 'max:255'],
            'parameters.*.coded_values' => ['nullable', 'array'],
            'parameters.*.coded_values.*' => ['array:code,name'],
            'parameters.*.coded_values.*.code' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'parameters.*.coded_values.*.name' => ['required', 'string', 'max:200'],
            'required_capability_code' => ['nullable', 'string', 'max:100'],
            'availability_status' => ['required', Rule::in([
                LabTestDefinition::AVAILABILITY_AVAILABLE,
                LabTestDefinition::AVAILABILITY_TEMPORARILY_UNAVAILABLE,
                LabTestDefinition::AVAILABILITY_REFERRAL_ONLY,
                LabTestDefinition::AVAILABILITY_DISABLED,
            ])],
            'turnaround_target_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{
     *     code: string, name: string, category: string, description: string|null,
     *     specimen_type: string, container_type: string, parameters: list<array<string, mixed>>,
     *     required_capability_code: string|null, availability_status: string,
     *     turnaround_target_minutes: int|null
     * }
     */
    public function dataForCreate(): array
    {
        return [
            'code' => (string) $this->validated('code'),
            ...$this->validatedDefinitionAttributes(),
        ];
    }

    /**
     * @return array{
     *     name: string, category: string, description: string|null,
     *     specimen_type: string, container_type: string, parameters: list<array<string, mixed>>,
     *     required_capability_code: string|null, availability_status: string,
     *     turnaround_target_minutes: int|null, active?: bool
     * }
     */
    public function dataForUpdate(): array
    {
        return $this->validatedDefinitionAttributes();
    }

    /**
     * @return array{
     *     name: string, category: string, description: string|null,
     *     specimen_type: string, container_type: string, parameters: list<array<string, mixed>>,
     *     required_capability_code: string|null, availability_status: string,
     *     turnaround_target_minutes: int|null, active?: bool
     * }
     */
    private function validatedDefinitionAttributes(): array
    {
        $data = $this->validated();

        if (! is_string($data['name'] ?? null)
            || ! is_string($data['category'] ?? null)
            || ! is_string($data['specimen_type'] ?? null)
            || ! is_string($data['container_type'] ?? null)
            || ! is_string($data['availability_status'] ?? null)
            || ! is_array($data['parameters'] ?? null)) {
            throw new LogicException('Validated laboratory test input did not match its request contract.');
        }

        $parameters = [];
        foreach (array_values($data['parameters']) as $parameter) {
            if (! is_array($parameter)) {
                throw new LogicException('Validated laboratory parameter input did not match its request contract.');
            }

            $normalizedParameter = [];
            foreach ($parameter as $key => $value) {
                if (! is_string($key)) {
                    throw new LogicException('Validated laboratory parameter input did not match its request contract.');
                }

                $normalizedParameter[$key] = $value;
            }

            $parameters[] = $normalizedParameter;
        }

        $turnaroundTargetMinutes = $data['turnaround_target_minutes'] ?? null;
        if ($turnaroundTargetMinutes !== null && ! is_numeric($turnaroundTargetMinutes)) {
            throw new LogicException('Validated laboratory turnaround input did not match its request contract.');
        }

        $attributes = [
            'name' => $data['name'],
            'category' => $data['category'],
            'description' => is_string($data['description'] ?? null) ? $data['description'] : null,
            'specimen_type' => $data['specimen_type'],
            'container_type' => $data['container_type'],
            'parameters' => $parameters,
            'required_capability_code' => is_string($data['required_capability_code'] ?? null) ? $data['required_capability_code'] : null,
            'availability_status' => $data['availability_status'],
            'turnaround_target_minutes' => $turnaroundTargetMinutes === null ? null : (int) $turnaroundTargetMinutes,
        ];

        if (array_key_exists('active', $data)) {
            $attributes['active'] = (bool) $data['active'];
        }

        return $attributes;
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $parameters = $this->input('parameters', []);
                if (! is_array($parameters)) {
                    return;
                }

                foreach ($parameters as $index => $parameter) {
                    if (! is_array($parameter)) {
                        continue;
                    }

                    $ranges = $parameter['reference_ranges'] ?? [];
                    if (isset($ranges['low'], $ranges['high']) && $ranges['low'] > $ranges['high']) {
                        $validator->errors()->add("parameters.{$index}.reference_ranges", 'The lower reference limit must not exceed the upper limit.');
                    }

                    if (($parameter['value_type'] ?? null) === 'CODED' && empty($parameter['coded_values'])) {
                        $validator->errors()->add("parameters.{$index}.coded_values", 'Coded parameters must define at least one result option.');
                    }
                }
            },
        ];
    }
}
