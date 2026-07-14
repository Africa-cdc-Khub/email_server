<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_api_docs_hidden_in_production_by_default(): void
    {
        config(['app.env' => 'production', 'app.api_docs_enabled' => false]);

        $this->get('/api/documentation')->assertNotFound();
        $this->get('/api/docs.json')->assertNotFound();
    }

    public function test_api_docs_available_when_enabled_in_production(): void
    {
        config(['app.env' => 'production', 'app.api_docs_enabled' => true]);

        $this->get('/api/documentation')->assertOk();
        $this->get('/api/docs.json')->assertOk();
    }
}
