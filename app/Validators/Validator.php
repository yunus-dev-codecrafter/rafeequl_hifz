<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

/**
 * Declarative input validator (conventions §13).
 *
 * Subclasses define rules(), optional labels (attributes()) and
 * optional custom messages(). validate() returns sanitized values or
 * throws ValidationException (422, per-field errors).
 *
 * Supported rules:
 *   required | integer | numeric | string | boolean | array | email | date
 *   min:N | max:N | minLength:N | maxLength:N | in:a,b,c | each:<rule>
 *
 * Normalization (trimming, casting) happens here — services receive
 * already-cleaned input and never coerce silently.
 *
 * Empty-input contract (Prompt 24): for an optional field, `""` and
 * whitespace-only strings mean "not provided" and normalize to `null`,
 * exactly like an absent key — downstream `!== null` checks therefore
 * never see a raw empty string (which used to cause TypeErrors / SQL
 * errors downstream). Fields listed in rejectEmpty() are the exception:
 * present-but-empty input is rejected (422) instead.
 */
abstract class Validator
{
    /** @return array<string, string|array<int, string>> field => 'rule|rule:arg' (or list of rules) */
    abstract protected function rules(): array;

    /** @return array<string, string> field => human label */
    protected function attributes(): array
    {
        return [];
    }

    /** @return array<string, string> "field.rule" => custom message */
    protected function messages(): array
    {
        return [];
    }

    /**
     * Optional fields where present-but-empty input (`""` / whitespace)
     * must be rejected (422) rather than treated as "not provided".
     *
     * @return array<int, string>
     */
    protected function rejectEmpty(): array
    {
        return [];
    }

    /** @var array<string, string> */
    private const DEFAULT_MESSAGES = [
        'required' => 'is required',
        'integer' => 'must be an integer',
        'numeric' => 'must be a number',
        'string' => 'must be a text value',
        'boolean' => 'must be a boolean',
        'array' => 'must be an array',
        'email' => 'must be a valid email address',
        'date' => 'must be a valid date (YYYY-MM-DD)',
        'min' => 'must be at least {min}',
        'max' => 'must be at most {max}',
        'minLength' => 'must be at least {min} characters',
        'maxLength' => 'must be at most {max} characters',
        'in' => 'must be one of: {allowed}',
        'each' => 'contains an invalid item',
    ];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> sanitized values
     * @throws ValidationException
     */
    final public function validate(array $input): array
    {
        $errors = [];
        $clean = [];

        foreach ($this->rules() as $field => $definition) {
            $rules = is_array($definition) ? $definition : explode('|', (string) $definition);

            $value = $input[$field] ?? null;
            $isEmpty = $value === null || $value === '' || (is_string($value) && trim($value) === '');
            $required = in_array('required', $rules, true);

            if ($isEmpty) {
                if ($required) {
                    $errors[] = $this->error($field, 'required');
                } elseif ($value !== null && in_array($field, $this->rejectEmpty(), true)) {
                    $errors[] = $this->error($field, 'required');
                } else {
                    // Empty == absent: never let "" reach a typed consumer.
                    $clean[$field] = null;
                }
                continue;
            }

            $failed = false;
            foreach ($rules as $rule) {
                if ($rule === 'required') {
                    continue;
                }
                [$name, $args] = $this->parseRule((string) $rule);
                $message = $this->applyRule($name, $args, $value, $field);
                if ($message !== null) {
                    $errors[] = ['field' => $field, 'message' => $message];
                    $failed = true;
                    break;
                }
            }

            if (!$failed) {
                $clean[$field] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        return $clean;
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function parseRule(string $rule): array
    {
        $parts = explode(':', $rule, 2);
        $args = isset($parts[1]) && $parts[1] !== '' ? explode(',', $parts[1]) : [];
        return [$parts[0], $args];
    }

    /**
     * Applies one rule, normalizing $value in place.
     * Returns an error message, or null when the rule passes.
     *
     * @param array<int, string> $args
     */
    private function applyRule(string $name, array $args, mixed &$value, string $field): ?string
    {
        switch ($name) {
            case 'integer':
                if (!is_numeric($value) || (float) (string) (int) $value !== (float) $value) {
                    return $this->messageFor($field, 'integer');
                }
                $value = (int) $value;
                return null;

            case 'numeric':
                if (!is_numeric($value)) {
                    return $this->messageFor($field, 'numeric');
                }
                if (is_string($value)) {
                    $value = str_contains($value, '.') ? (float) $value : (int) $value;
                }
                return null;

            case 'string':
                if (is_array($value) || is_object($value)) {
                    return $this->messageFor($field, 'string');
                }
                $value = trim((string) $value);
                return null;

            case 'boolean':
                if (is_bool($value)) {
                    $value = (int) $value;
                    return null;
                }
                $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed === null) {
                    return $this->messageFor($field, 'boolean');
                }
                $value = (int) $parsed;
                return null;

            case 'array':
                return is_array($value) ? null : $this->messageFor($field, 'array');

            case 'email':
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    return $this->messageFor($field, 'email');
                }
                $value = trim($value);
                return null;

            case 'date':
                if (!is_string($value)) {
                    return $this->messageFor($field, 'date');
                }
                $date = \DateTime::createFromFormat('Y-m-d', $value);
                if ($date === false || $date->format('Y-m-d') !== $value) {
                    return $this->messageFor($field, 'date');
                }
                return null;

            case 'min':
                if (!$this->hasNumericArg($args)) {
                    throw new \LogicException('Rule min requires a numeric argument');
                }
                if (!is_numeric($value)) {
                    return $this->messageFor($field, 'numeric');
                }
                return (float) $value < (float) $args[0] ? $this->messageFor($field, 'min', ['{min}' => $args[0]]) : null;

            case 'max':
                if (!$this->hasNumericArg($args)) {
                    throw new \LogicException('Rule max requires a numeric argument');
                }
                if (!is_numeric($value)) {
                    return $this->messageFor($field, 'numeric');
                }
                return (float) $value > (float) $args[0] ? $this->messageFor($field, 'max', ['{max}' => $args[0]]) : null;

            case 'minLength':
                if (!$this->hasNumericArg($args)) {
                    throw new \LogicException('Rule minLength requires a numeric argument');
                }
                if (!is_scalar($value)) {
                    return $this->messageFor($field, 'string');
                }
                return mb_strlen((string) $value) < (int) $args[0]
                    ? $this->messageFor($field, 'minLength', ['{min}' => $args[0]])
                    : null;

            case 'maxLength':
                if (!$this->hasNumericArg($args)) {
                    throw new \LogicException('Rule maxLength requires a numeric argument');
                }
                if (!is_scalar($value)) {
                    return $this->messageFor($field, 'string');
                }
                return mb_strlen((string) $value) > (int) $args[0]
                    ? $this->messageFor($field, 'maxLength', ['{max}' => $args[0]])
                    : null;

            case 'in':
                if ($args === []) {
                    throw new \LogicException('Rule in requires at least one allowed value');
                }
                return in_array((string) $value, $args, true) ? null : $this->messageFor($field, 'in', ['{allowed}' => implode(', ', $args)]);

            case 'each':
                if (count($args) !== 1) {
                    throw new \LogicException('Rule each requires exactly one inner rule');
                }
                if (!is_array($value)) {
                    return $this->messageFor($field, 'array');
                }
                [$innerName, $innerArgs] = $this->parseRule($args[0]);
                foreach ($value as $index => $item) {
                    $itemValue = $item;
                    $message = $this->applyRule($innerName, $innerArgs, $itemValue, $field);
                    if ($message !== null) {
                        return $this->messageFor($field, 'each');
                    }
                    $value[$index] = $itemValue;
                }
                return null;

            default:
                throw new \LogicException('Unsupported validation rule: ' . $name);
        }
    }

    /** @param array<int, string> $args */
    private function hasNumericArg(array $args): bool
    {
        return $args !== [] && is_numeric($args[0]);
    }

    /** @param array<int, array<string, mixed>|string> $errors */
    private function error(string $field, string $rule): array
    {
        return ['field' => $field, 'message' => $this->messageFor($field, $rule)];
    }

    /** @param array<string, string> $replacements */
    private function messageFor(string $field, string $rule, array $replacements = []): string
    {
        $custom = $this->messages()[$field . '.' . $rule] ?? null;
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

        $template = self::DEFAULT_MESSAGES[$rule] ?? 'is invalid';
        $label = $this->attributes()[$field] ?? $field;

        $text = strtr($template, $replacements + ['{field}' => $field]);
        return $label . ' ' . $text;
    }
}
