<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareDomain;

use Agovena\Modules\Domains\Contracts\DomainRegistrar;
use Agovena\Modules\Domains\Contracts\RefreshesRegistrationStatus;
use Agovena\Modules\Domains\Models\DomainRegistration;
use App\Support\MoneyFormatter;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CloudflareRegistrar implements DomainRegistrar, RefreshesRegistrationStatus
{
    private const TERMINAL_STATES = ['succeeded', 'failed'];

    public function __construct(
        private readonly CloudflareApi $api,
    ) {}

    public function key(): string
    {
        return 'cloudflare-registrar';
    }

    public function capabilities(): array
    {
        return ['availability_check', 'registration'];
    }

    public function checkAvailability(string $domain): array
    {
        $domain = $this->normalizeDomain($domain);
        $entry = $this->firstDomain($this->api->check([$domain]), $domain);
        $currency = strtoupper((string) ($entry['pricing']['currency'] ?? ''));
        $cost = $entry['pricing']['registration_cost'] ?? null;
        $priceMinor = null;
        if ($currency !== '' && is_string($cost) && $cost !== '') {
            try {
                $priceMinor = MoneyFormatter::minorFromMajorInput($cost, $currency);
            } catch (InvalidArgumentException) {
                $priceMinor = null;
            }
        }

        return [
            'available' => $this->isRegistrable($entry),
            'domain' => (string) ($entry['name'] ?? $domain),
            'price_minor' => $priceMinor,
            'currency' => $currency !== '' ? $currency : null,
            'reason' => $this->unavailableReason($entry),
        ];
    }

    /**
     * Idempotent for a given domain: Cloudflare permits one registration per domain and
     * exposes its workflow at /registrations/{domain}/registration-status. An existing
     * non-failed workflow is reported instead of submitting a second billable request,
     * so calling this again acts as a status refresh (action_required is never resubmitted).
     */
    public function register(DomainRegistration $registration): array
    {
        $domain = $this->normalizeDomain((string) $registration->domain_name);

        $existing = $this->api->registrationStatus($domain);
        if ($existing !== null) {
            $mapped = $this->mapWorkflow($existing, $domain);
            if ($mapped['meta']['workflow_state'] !== 'failed') {
                return $mapped;
            }
        }

        $entry = $this->firstDomain($this->api->check([$domain]), $domain);
        if (! $this->isRegistrable($entry)) {
            throw new RuntimeException(
                'Cloudflare reports this domain is not registrable through the Registrar API ('
                .($this->unavailableReason($entry) ?? 'unknown').').',
            );
        }

        try {
            $response = $this->api->register($domain, [
                'auto_renew' => (bool) $registration->auto_renew,
            ]);
        } catch (RuntimeException $exception) {
            return $this->reconcile($domain, $existing, $exception);
        }

        return $this->mapWorkflow($response, $domain);
    }

    public function renew(DomainRegistration $registration, int $years = 1): array
    {
        unset($registration, $years);

        throw new CloudflareRegistrarOperationNotSupported(
            'The Cloudflare Registrar API does not expose a renewal operation.',
        );
    }

    /** Reads GET /registration-status only; never submits a registration. */
    public function refreshRegistration(DomainRegistration $registration): array
    {
        $domain = $this->normalizeDomain((string) $registration->domain_name);
        $status = $this->api->registrationStatus($domain);
        if ($status === null) {
            throw new RuntimeException('Cloudflare reports no registration workflow for this domain.');
        }

        return $this->mapWorkflow($status, $domain);
    }

    /**
     * The registration request outcome is unknown (transport error or rejection). Read the
     * workflow once: if Cloudflare started a new workflow, report it instead of failing.
     *
     * @param  array<string, mixed>|null  $previous
     * @return array{provider_reference: string, expires_at: string|null, status: string, meta: array<string, mixed>}
     */
    private function reconcile(string $domain, ?array $previous, RuntimeException $exception): array
    {
        try {
            $current = $this->api->registrationStatus($domain);
        } catch (Throwable) {
            throw $exception;
        }

        if ($current === null || ($previous !== null && $this->createdAt($current) === $this->createdAt($previous))) {
            throw $exception;
        }

        return $this->mapWorkflow($current, $domain);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{provider_reference: string, expires_at: string|null, status: string, meta: array<string, mixed>}
     */
    private function mapWorkflow(array $response, string $domain): array
    {
        $result = is_array($response['result'] ?? null) ? $response['result'] : [];
        $state = $result['state'] ?? null;
        $status = match ($state) {
            'pending', 'in_progress', 'action_required', 'blocked' => 'registering',
            'failed' => 'failed',
            'succeeded' => 'active',
            default => throw new RuntimeException('Cloudflare returned an unknown registration workflow state.'),
        };
        if (($result['completed'] ?? null) !== in_array($state, self::TERMINAL_STATES, true)) {
            throw new RuntimeException('Cloudflare returned an inconsistent registration workflow state.');
        }
        $context = is_array($result['context'] ?? null) ? $result['context'] : [];
        if (($context['domain_name'] ?? null) !== $domain) {
            throw new RuntimeException('Cloudflare returned a registration workflow for a different domain.');
        }
        $registered = is_array($context['registration'] ?? null) ? $context['registration'] : [];
        if ($state === 'succeeded' && ($registered['domain_name'] ?? null) !== $domain) {
            throw new RuntimeException('Cloudflare did not return the completed domain registration.');
        }
        $error = is_array($result['error'] ?? null) ? $result['error'] : [];
        $links = is_array($result['links'] ?? null) ? $result['links'] : [];

        return [
            'provider_reference' => $domain,
            'expires_at' => isset($registered['expires_at']) ? (string) $registered['expires_at'] : null,
            'status' => $status,
            'meta' => array_filter([
                'workflow_state' => $state,
                'workflow_status_path' => isset($links['self']) ? (string) $links['self'] : null,
                'workflow_updated_at' => isset($result['updated_at']) ? (string) $result['updated_at'] : null,
                'workflow_action' => isset($context['action']) ? (string) $context['action'] : null,
                'workflow_confirmation_sent_to' => isset($context['confirmation_sent_to']) ? (string) $context['confirmation_sent_to'] : null,
                'workflow_error_code' => isset($error['code']) ? (string) $error['code'] : null,
                'workflow_error_message' => isset($error['message']) ? (string) $error['message'] : null,
            ], static fn (?string $value): bool => $value !== null),
        ];
    }

    /** @param array<string, mixed> $response */
    private function createdAt(array $response): ?string
    {
        $result = is_array($response['result'] ?? null) ? $response['result'] : [];

        return isset($result['created_at']) ? (string) $result['created_at'] : null;
    }

    /** @param array<string, mixed> $entry */
    private function isRegistrable(array $entry): bool
    {
        return ($entry['registrable'] ?? false) === true && ($entry['tier'] ?? 'standard') !== 'premium';
    }

    /** @param array<string, mixed> $entry */
    private function unavailableReason(array $entry): ?string
    {
        if (($entry['tier'] ?? null) === 'premium') {
            return 'domain_premium';
        }
        if ($this->isRegistrable($entry)) {
            return null;
        }

        return isset($entry['reason']) ? (string) $entry['reason'] : 'domain_unavailable';
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function firstDomain(array $response, string $domain): array
    {
        $result = is_array($response['result'] ?? null) ? $response['result'] : $response;
        $domains = is_array($result['domains'] ?? null) ? $result['domains'] : [];
        foreach ($domains as $entry) {
            if (is_array($entry) && strtolower((string) ($entry['name'] ?? '')) === $domain) {
                return $entry;
            }
        }

        return [
            'name' => $domain,
            'registrable' => false,
            'reason' => 'domain_not_returned',
        ];
    }

    private function normalizeDomain(string $domain): string
    {
        $normalized = strtolower(rtrim(trim($domain), '.'));
        if ($normalized === '' || ! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/', $normalized)) {
            throw new InvalidArgumentException('A fully qualified ASCII domain is required.');
        }

        return $normalized;
    }
}
