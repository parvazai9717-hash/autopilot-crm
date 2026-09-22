<?php

namespace Tests\Security;

use Tests\TestCase;

/**
 * Phase 0 — Reproduction test for finding F4 (SSRF via audio_url).
 *
 * The meeting-ingest n8n workflow downloads body.audio_url with the CRM
 * API key attached. An unauthenticated caller can make n8n exfiltrate the
 * key to any URL they control.
 *
 * The CRM-side fix (pre n8n rework in Phase 5): the signed audio URL
 * that the CRM emits to n8n must be validated to only point at the CRM
 * API host before n8n is allowed to download it. This test verifies the
 * validation logic once it is implemented.
 *
 * For now these tests are marked incomplete/repro, documenting the defect.
 * They turn GREEN in Phase 5 when AudioUrlValidator is implemented.
 */
class F4SsrfTest extends TestCase
{
    /**
     * @test
     * @group repro
     */
    public function audio_url_from_external_host_is_rejected(): void
    {
        // The MeetingController (or a dedicated AudioUrlValidator service)
        // must reject audio URLs that do not point at the configured API host.
        $this->markTestIncomplete(
            'F4 repro — AudioUrlValidator not yet implemented. ' .
            'This test becomes green in Phase 5. ' .
            'Evidence: meeting-ingest HTTP Request1 downloads body.audio_url ' .
            'with "Header Auth account 2" (QDpOvRubD545BMZ5) attached, ' .
            'meaning any caller-supplied URL receives the CRM API key.'
        );
    }

    /**
     * @test
     * @group repro
     */
    public function audio_url_must_match_crm_api_host(): void
    {
        $this->markTestIncomplete(
            'F4 repro — implement AudioUrlValidator::validate(string $url): void ' .
            'that throws InvalidArgumentException for non-CRM hosts. ' .
            'See docs/repro/04-f4-ssrf.md for steps and evidence.'
        );
    }
}
