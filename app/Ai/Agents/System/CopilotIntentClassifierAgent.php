<?php

namespace App\Ai\Agents\System;

use App\Ai\Services\UsersCopilotCapabilityCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Agente clasificador ligero para rescatar prompts que el planner determinístico no resuelve.
 *
 * Este agente NO ejecuta acciones, solo clasifica intenciones contra el CapabilityCatalog.
 * Se invoca como capa de rescate cuando el planner retorna fallback o ambiguous.
 */
class CopilotIntentClassifierAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        $capabilities = collect(UsersCopilotCapabilityCatalog::all())
            ->map(fn (array $def): string => sprintf(
                '- %s (family: %s, intent: %s, filters: %s)',
                $def['key'],
                $def['family'],
                $def['intent_family'],
                implode(', ', $def['required_filters']) ?: 'none'
            ))
            ->implode("\n");

        $filterSchema = collect(UsersCopilotCapabilityCatalog::filterSchema())
            ->map(fn (array $schema, string $key): string => match ($schema['type']) {
                'enum' => sprintf('- %s: enum(%s)', $key, implode(', ', $schema['values'])),
                'array' => sprintf('- %s: array of %s', $key, $schema['items'] ?? 'mixed'),
                default => sprintf('- %s: %s', $key, $schema['type']),
            })
            ->implode("\n");

        return <<<INSTRUCTIONS
Eres un clasificador de intenciones para un sistema de gestión de usuarios administrativo en español.

Tu tarea es analizar el prompt del usuario y devolver la capability más apropiada del catálogo.

CATÁLOGO DE CAPABILITIES:
{$capabilities}

FILTROS VÁLIDOS:
{$filterSchema}

REGLAS:
1. Solo puedes usar capability keys que existen en el catálogo. NUNCA inventes nuevas capabilities.
2. Los filtros deben ser relevantes para la capability seleccionada.
3. Si no hay una capability clara, usa intent_family: 'help' y capability_key: 'users.help'.
4. El dominio es gestión de usuarios: métricas, búsquedas, detalles, acciones (activar/desactivar/reset).
5. La respuesta debe incluir intent_family, capability_key, filters (objeto), y confidence (0-1).
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'intent_family' => $schema->string()->enum([
                'read_metrics',
                'read_search',
                'read_detail',
                'action_proposal',
                'read_explain',
                'help',
                'ambiguous',
            ])->required(),
            'capability_key' => $schema->string()->required(),
            'filters' => $schema->object()->required(),
            'confidence' => $schema->number()->minimum(0)->maximum(1)->required(),
        ];
    }
}
