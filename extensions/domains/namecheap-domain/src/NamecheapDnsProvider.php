<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapDomain;

use Agovena\Modules\Domains\Contracts\DomainDnsProvider;
use Agovena\Modules\Domains\Models\DomainRegistration;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

/**
 * Host records on Namecheap BasicDNS. domains.dns.setHosts replaces the complete host list,
 * so every change reads all hosts, changes one entry and writes the full list back while a
 * per-domain lock is held. Hosts of other types (URL, CAA, ALIAS, NS, ...) are preserved as read.
 */
final class NamecheapDnsProvider implements DomainDnsProvider
{
    private const EDITABLE_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];

    /** EmailType values for which custom MX hosts are allowed. */
    private const CUSTOM_MX_EMAIL_TYPES = ['MX', 'NONE'];

    private const LOCK_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 20;

    private const DOMAIN_PATTERN = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/';

    private const HOST_PATTERN = '/\A(?:\*|[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)(?:\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)*\z/';

    public function __construct(
        private readonly NamecheapDnsApi $api,
    ) {}

    public function key(): string
    {
        return 'namecheap-dns';
    }

    public function capabilities(): array
    {
        return ['zone_management', 'records'];
    }

    /**
     * Read-only: confirms the domain is in this account and served by Namecheap BasicDNS.
     * Nameservers are never changed.
     */
    public function ensureZone(DomainRegistration $registration): array
    {
        $domain = $this->domain($registration);
        [$sld, $tld] = $this->split($domain);
        $list = $this->api->nameservers($sld, $tld);
        if ($list['domain'] !== $domain) {
            throw new RuntimeException('Namecheap returned DNS details for a different domain.');
        }
        if (! $list['using_namecheap_dns']) {
            throw new NamecheapOperationNotSupported('The domain does not use Namecheap BasicDNS; changing nameservers is not supported.');
        }

        return [
            'zone_reference' => $domain,
            'nameservers' => $list['nameservers'],
            'status' => 'active',
            'meta' => ['domain' => $domain],
        ];
    }

    public function listRecords(DomainRegistration $registration): array
    {
        $domain = $this->domain($registration);

        return array_map(fn (array $host): array => $this->present($host), $this->hosts($domain)['hosts']);
    }

    public function upsertRecord(DomainRegistration $registration, array $record): array
    {
        $domain = $this->domain($registration);
        $host = $this->validateRecord($record, $domain);
        $reference = trim((string) ($record['id'] ?? ''));

        $this->mutate($domain, function (array $hosts) use ($host, $reference): array {
            if ($reference === '') {
                $hosts[] = $host;

                return $hosts;
            }

            $index = $this->indexOf($hosts, $reference);
            if (! in_array($hosts[$index]['type'], self::EDITABLE_TYPES, true)) {
                throw new InvalidArgumentException('This DNS record type is not supported.');
            }
            $hosts[$index] = $host;

            return $hosts;
        });

        return $this->present($host);
    }

    public function deleteRecord(DomainRegistration $registration, string $recordReference): array
    {
        $domain = $this->domain($registration);
        $recordReference = trim($recordReference);

        $this->mutate($domain, function (array $hosts) use ($recordReference): array {
            unset($hosts[$this->indexOf($hosts, $recordReference)]);

            return array_values($hosts);
        });

        return ['id' => $recordReference, 'deleted' => true];
    }

    /**
     * @param  callable(list<array{name: string, type: string, address: string, mx_pref: int|null, ttl: int|null}>): list<array{name: string, type: string, address: string, mx_pref: int|null, ttl: int|null}>  $change
     */
    private function mutate(string $domain, callable $change): void
    {
        [$sld, $tld] = $this->split($domain);

        try {
            Cache::lock('namecheap-domain:dns:'.$domain, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, function () use ($domain, $sld, $tld, $change): void {
                $current = $this->hosts($domain);
                if (! $current['using_namecheap_dns']) {
                    throw new NamecheapOperationNotSupported('The domain does not use Namecheap BasicDNS; host records cannot be changed.');
                }
                $hosts = $change($current['hosts']);
                $this->api->setHosts($sld, $tld, $hosts, $this->emailType($current['email_type'], $hosts));
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Another DNS change for this domain is still in progress; try again.');
        }
    }

    /** @return array{domain: string|null, using_namecheap_dns: bool, email_type: string|null, hosts: list<array{name: string, type: string, address: string, mx_pref: int|null, ttl: int|null}>} */
    private function hosts(string $domain): array
    {
        [$sld, $tld] = $this->split($domain);
        $hosts = $this->api->hosts($sld, $tld);
        if ($hosts['domain'] !== $domain) {
            throw new RuntimeException('Namecheap returned DNS hosts for a different domain.');
        }

        return $hosts;
    }

    /**
     * Custom MX hosts need EmailType MX. A domain using Namecheap email forwarding or a mail
     * product (FWD, OX, MXE, ...) is refused instead of silently switching its mail setup.
     *
     * @param  list<array{name: string, type: string, address: string, mx_pref: int|null, ttl: int|null}>  $hosts
     */
    private function emailType(?string $current, array $hosts): ?string
    {
        $current = $current !== null ? strtoupper($current) : null;
        if (! in_array('MX', array_column($hosts, 'type'), true)) {
            return $current;
        }
        if ($current !== null && ! in_array($current, self::CUSTOM_MX_EMAIL_TYPES, true)) {
            throw new NamecheapOperationNotSupported('The domain uses a Namecheap mail setting ('.$current.'); custom MX records are not supported for it.');
        }

        return 'MX';
    }

    /**
     * Namecheap host IDs are not stable across setHosts, so records are referenced by a
     * fingerprint of their content. A changed or removed record no longer matches.
     *
     * @param  array{name: string, type: string, address: string, mx_pref: int|null, ttl: int|null}  $host
     */
    private function reference(array $host): string
    {
        return substr(hash('sha256', implode("\n", [
            $host['type'],
            strtolower($host['name']),
            $host['address'],
            $host['type'] === 'MX' ? (string) $host['mx_pref'] : '',
        ])), 0, 24);
    }

    /** @param list<array{name: string, type: string, address: string, mx_pref: int|null, ttl: int|null}> $hosts */
    private function indexOf(array $hosts, string $reference): int
    {
        foreach ($hosts as $index => $host) {
            if (hash_equals($this->reference($host), $reference)) {
                return $index;
            }
        }

        throw new RuntimeException('The DNS record no longer exists at Namecheap; reload the records and try again.');
    }

    /**
     * @param  array{name: string, type: string, address: string, mx_pref: int|null, ttl: int|null}  $host
     * @return array<string, mixed>
     */
    private function present(array $host): array
    {
        $record = [
            'id' => $this->reference($host),
            'type' => $host['type'],
            'name' => $host['name'],
            'content' => $host['address'],
            'ttl' => $host['ttl'],
        ];
        if ($host['type'] === 'MX') {
            $record['priority'] = $host['mx_pref'];
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{name: string, type: string, address: string, mx_pref: int|null, ttl: int}
     */
    private function validateRecord(array $record, string $domain): array
    {
        $type = strtoupper(trim((string) ($record['type'] ?? '')));
        $content = trim((string) ($record['content'] ?? ''));
        $ttl = filter_var($record['ttl'] ?? 1800, FILTER_VALIDATE_INT, ['options' => ['min_range' => 60, 'max_range' => 60000]]);

        if (! in_array($type, self::EDITABLE_TYPES, true)) {
            throw new InvalidArgumentException('This DNS record type is not supported.');
        }
        if ($content === '' || strlen($content) > 2048 || preg_match('/[\r\n]/', $content)) {
            throw new InvalidArgumentException('A valid DNS record value is required.');
        }
        if (! is_int($ttl)) {
            throw new InvalidArgumentException('Namecheap DNS TTL must be between 60 and 60000 seconds.');
        }
        if ($type === 'A' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('An IPv4 address is required for an A record.');
        }
        if ($type === 'AAAA' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException('An IPv6 address is required for an AAAA record.');
        }

        $priority = null;
        if ($type === 'MX') {
            $priority = filter_var($record['priority'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
            if (! is_int($priority)) {
                throw new InvalidArgumentException('MX records require a priority between 0 and 65535.');
            }
        }

        return [
            'name' => $this->relativeName((string) ($record['name'] ?? ''), $domain),
            'type' => $type,
            'address' => $content,
            'mx_pref' => $priority,
            'ttl' => $ttl,
        ];
    }

    /** Namecheap host names are relative to the domain, with "@" for the apex. */
    private function relativeName(string $name, string $domain): string
    {
        $name = strtolower(rtrim(trim($name), '.'));
        if ($name === '' || $name === '@' || $name === $domain) {
            return '@';
        }
        if (str_ends_with($name, '.'.$domain)) {
            $name = substr($name, 0, -strlen('.'.$domain));
        }
        if (strlen($name.'.'.$domain) > 253 || ! preg_match(self::HOST_PATTERN, $name)) {
            throw new InvalidArgumentException('A valid DNS record name is required.');
        }

        return $name;
    }

    private function domain(DomainRegistration $registration): string
    {
        $domain = strtolower(rtrim(trim((string) $registration->domain_name), '.'));
        if ($domain === '' || ! preg_match(self::DOMAIN_PATTERN, $domain)) {
            throw new InvalidArgumentException('A fully qualified ASCII domain is required.');
        }

        return $domain;
    }

    /** @return array{0: string, 1: string} SLD and TLD as Namecheap expects them. */
    private function split(string $domain): array
    {
        [$sld, $tld] = explode('.', $domain, 2);

        return [$sld, $tld];
    }
}
