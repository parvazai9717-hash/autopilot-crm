<?php

namespace Tests\Security;

use Tests\TestCase;

/**
 * Phase 0 — Regression test: no real secrets in .env.example.
 *
 * This test MUST stay in the suite forever. It is the canonical proof
 * that Secrets never go in git (brief rule 6).
 */
class SecretsInGitTest extends TestCase
{
    private string $envExamplePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envExamplePath = base_path('.env.example');
    }

    /** @test */
    public function env_example_does_not_contain_real_app_key(): void
    {
        $content = file_get_contents($this->envExamplePath);
        $this->assertStringNotContainsString(
            'aMv8HRlzqjrAQVYrr9juWYxLyzgjubm4lNWCrWVmZIA=',
            $content,
            'Real APP_KEY found in .env.example — rotate immediately and run git filter-repo.'
        );
        $this->assertMatchesRegularExpression(
            '/APP_KEY=base64:CHANGE_ME/i',
            $content,
            '.env.example must have a CHANGE_ME placeholder for APP_KEY.'
        );
    }

    /** @test */
    public function env_example_does_not_contain_real_db_password(): void
    {
        $content = file_get_contents($this->envExamplePath);
        $this->assertStringNotContainsString(
            '@Malik1122',
            $content,
            'Real DB_PASSWORD found in .env.example — rotate immediately and run git filter-repo.'
        );
    }

    /** @test */
    public function env_example_does_not_contain_real_n8n_api_key(): void
    {
        $content = file_get_contents($this->envExamplePath);
        $this->assertStringNotContainsString(
            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
            $content,
            'Real N8N_API_KEY JWT found in .env.example — rotate immediately and run git filter-repo.'
        );
    }

    /** @test */
    public function env_example_does_not_contain_real_webhook_secret(): void
    {
        $content = file_get_contents($this->envExamplePath);
        $this->assertStringNotContainsString(
            '608a0d95-8b89-4a92-ad7c-be88716b9766',
            $content,
            'Real WEBHOOK_SIGNING_SECRET found in .env.example — rotate immediately.'
        );
    }
}
