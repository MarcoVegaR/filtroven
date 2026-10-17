<?php

namespace App\Support\Scaffold;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ScaffoldField
{
    /**
     * @param  list<string>  $options
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $required,
        public bool $nullable,
        public bool $list,
        public bool $searchable,
        public bool $sortable,
        public array $options = [],
    ) {
        if ($this->required && $this->nullable) {
            throw new InvalidArgumentException("Field [{$this->name}] cannot be both required and nullable.");
        }
    }

    public function studlyName(): string
    {
        return Str::studly($this->name);
    }

    public function label(): string
    {
        return Str::headline($this->name);
    }

    public function migrationColumn(): string
    {
        return match ($this->type) {
            'string', 'email' => "            \$table->string('{$this->name}')".$this->nullableSuffix().';',
            'text' => "            \$table->text('{$this->name}')".$this->nullableSuffix().';',
            'integer' => "            \$table->integer('{$this->name}')".$this->nullableSuffix().';',
            'decimal' => "            \$table->decimal('{$this->name}', 10, 2)".$this->nullableSuffix().';',
            'boolean' => "            \$table->boolean('{$this->name}')->default(false);",
            'date' => "            \$table->date('{$this->name}')".$this->nullableSuffix().';',
            'datetime' => "            \$table->dateTime('{$this->name}')".$this->nullableSuffix().';',
            'select' => "            \$table->string('{$this->name}')".$this->nullableSuffix().';',
            default => throw new InvalidArgumentException("Unsupported field type [{$this->type}]."),
        };
    }

    public function phpValidationRules(bool $forUpdate = false): string
    {
        $rules = [];

        if ($this->required) {
            $rules[] = "'required'";
        }

        if ($forUpdate && ! $this->required) {
            $rules[] = "'sometimes'";
        }

        if ($this->nullable) {
            $rules[] = "'nullable'";
        }

        $rules[] = match ($this->type) {
            'string', 'select' => "'string'",
            'text' => "'string'",
            'email' => "'email'",
            'integer' => "'integer'",
            'decimal' => "'numeric'",
            'boolean' => "'boolean'",
            'date' => "'date'",
            'datetime' => "'date'",
            default => throw new InvalidArgumentException("Unsupported field type [{$this->type}]."),
        };

        if (in_array($this->type, ['string', 'email', 'select'], true)) {
            $rules[] = "'max:255'";
        }

        if ($this->type === 'select') {
            $options = implode(', ', array_map(fn (string $option): string => "'{$option}'", $this->options));
            $rules[] = "Rule::in([{$options}])";
        }

        return '['.implode(', ', $rules).']';
    }

    public function modelCast(): ?string
    {
        return match ($this->type) {
            'boolean' => "            '{$this->name}' => 'boolean',",
            'date' => "            '{$this->name}' => 'date',",
            'datetime' => "            '{$this->name}' => 'datetime',",
            'decimal' => "            '{$this->name}' => 'decimal:2',",
            default => null,
        };
    }

    public function fakerValue(): string
    {
        return match ($this->type) {
            'string' => 'fake()->words(2, true)',
            'text' => 'fake()->sentence()',
            'integer' => 'fake()->numberBetween(1, 500)',
            'decimal' => 'fake()->randomFloat(2, 1, 999)',
            'boolean' => 'fake()->boolean()',
            'date' => 'fake()->date()',
            'datetime' => 'fake()->dateTime()->format(\'Y-m-d H:i:s\')',
            'email' => 'fake()->safeEmail()',
            'select' => "fake()->randomElement(['".implode("', '", $this->options)."'])",
            default => throw new InvalidArgumentException("Unsupported field type [{$this->type}]."),
        };
    }

    public function inputType(): string
    {
        return match ($this->type) {
            'text' => 'textarea',
            'boolean' => 'checkbox',
            'date' => 'date',
            'datetime' => 'datetime-local',
            'email' => 'email',
            'select' => 'select',
            'integer', 'decimal' => 'number',
            default => 'text',
        };
    }

    private function nullableSuffix(): string
    {
        return $this->nullable ? '->nullable()' : '';
    }
}
