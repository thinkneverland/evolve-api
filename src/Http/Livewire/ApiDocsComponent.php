<?php

namespace Thinkneverland\Evolve\Api\Http\Livewire;

use Livewire\Component;
use Thinkneverland\Evolve\Core\Support\ModelRegistry;

class ApiDocsComponent extends Component
{
    public $spec;
    public $models;
    public $selectedModel;
    public $endpoints = [];

    public function mount()
    {
        $this->models = ModelRegistry::getModels();
        $this->loadApiSpec();
    }

    protected function loadApiSpec()
    {
        $path = storage_path('app/evolve-api-docs.json');

        if (!file_exists($path)) {
            $this->dispatch('notify', [
                'message' => 'API documentation not found. Run php artisan evolve-api:generate-docs first.',
                'type' => 'error'
            ]);
            return;
        }

        $this->spec = json_decode(file_get_contents($path), true);
        $this->loadEndpoints();
    }

    protected function loadEndpoints()
    {
        if (!$this->spec || !isset($this->spec['paths'])) {
            return;
        }

        foreach ($this->spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $details) {
                $this->endpoints[] = [
                    'path' => $path,
                    'method' => strtoupper($method),
                    'summary' => $details['summary'] ?? '',
                    'description' => $details['description'] ?? '',
                    'parameters' => $details['parameters'] ?? [],
                    'responses' => $details['responses'] ?? [],
                    'tags' => $details['tags'] ?? []
                ];
            }
        }
    }

    public function updatedSelectedModel($value)
    {
        if ($value) {
            $this->endpoints = collect($this->endpoints)
                ->filter(fn($endpoint) => in_array($value, $endpoint['tags']))
                ->values()
                ->toArray();
        } else {
            $this->loadEndpoints();
        }
    }

    public function render()
    {
        return view('evolve-api::livewire.api-docs', [
            'endpoints' => collect($this->endpoints)
                ->groupBy('tags')
                ->toArray()
        ]);
    }
}
