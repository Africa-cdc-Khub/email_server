<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_api_docs_hidden_when_disabled(): void
    {
        // Routes are registered at boot from config — rebuild app with flag off
        putenv('API_DOCS_ENABLED=false');
        $_ENV['API_DOCS_ENABLED'] = 'false';
        $_SERVER['API_DOCS_ENABLED'] = 'false';
        $this->refreshApplication();

        $this->get('/api/documentation')->assertNotFound();
        $this->get('/api/docs.json')->assertNotFound();
    }

    public function test_api_docs_available_when_enabled(): void
    {
        putenv('API_DOCS_ENABLED=true');
        $_ENV['API_DOCS_ENABLED'] = 'true';
        $_SERVER['API_DOCS_ENABLED'] = 'true';
        $this->refreshApplication();

        $this->get('/api/documentation')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->get('/api/docs.json')->assertOk();
    }
}
