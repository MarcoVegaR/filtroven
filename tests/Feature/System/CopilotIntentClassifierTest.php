<?php

use App\Ai\Agents\System\CopilotIntentClassifierAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;

use function Pest\Laravel\mock;

describe('Copilot Intent Classifier Agent', function (): void {
    it('tiene instrucciones que incluyen el catalogo de capabilities', function (): void {
        $agent = new CopilotIntentClassifierAgent;
        $instructions = $agent->instructions();

        expect($instructions)->toContain('users.metrics.total');
        expect($instructions)->toContain('users.search');
        expect($instructions)->toContain('users.detail');
        expect($instructions)->toContain('users.actions.activate');
    });

    it('tiene instrucciones que incluyen el schema de filtros', function (): void {
        $agent = new CopilotIntentClassifierAgent;
        $instructions = $agent->instructions();

        expect($instructions)->toContain('query');
        expect($instructions)->toContain('status');
        expect($instructions)->toContain('user_id');
    });

    it('define schema con intent_family enum', function (): void {
        $agent = new CopilotIntentClassifierAgent;

        // Mock del JsonSchema
        $schema = Mockery::mock(JsonSchema::class);
        $schema->shouldReceive('string')->andReturnSelf();
        $schema->shouldReceive('enum')->with([
            'read_metrics',
            'read_search',
            'read_detail',
            'action_proposal',
            'read_explain',
            'help',
            'ambiguous',
        ])->andReturnSelf();
        $schema->shouldReceive('required')->andReturnSelf();
        $schema->shouldReceive('object')->andReturnSelf();
        $schema->shouldReceive('number')->andReturnSelf();
        $schema->shouldReceive('minimum')->andReturnSelf();
        $schema->shouldReceive('maximum')->andReturnSelf();

        $result = $agent->schema($schema);

        expect($result)->toHaveKeys(['intent_family', 'capability_key', 'filters', 'confidence']);
    });

    it('las instrucciones prohiben inventar capability keys', function (): void {
        $agent = new CopilotIntentClassifierAgent;
        $instructions = $agent->instructions();

        expect($instructions)->toContain('NUNCA inventes');
        expect($instructions)->toContain('capability keys');
    });
});
