<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareDomain;

interface CloudflareApi
{
    /**
     * POST /accounts/{account_id}/registrar/domain-check (read-only).
     *
     * @param  list<string>  $domains
     * @return array<string, mixed>
     */
    public function check(array $domains): array;

    /**
     * POST /accounts/{account_id}/registrar/registrations (billable).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function register(string $domain, array $payload = []): array;

    /**
     * GET /accounts/{account_id}/registrar/registrations/{domain_name}/registration-status.
     * Returns null when Cloudflare reports no registration workflow for the domain (HTTP 404).
     *
     * @return array<string, mixed>|null
     */
    public function registrationStatus(string $domain): ?array;
}
