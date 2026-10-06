<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapDomain;

/**
 * DNS commands of the Namecheap XML API. Domains are addressed as SLD + TLD.
 */
interface NamecheapDnsApi
{
    /**
     * namecheap.domains.dns.getList.
     *
     * @return array{domain: string|null, using_namecheap_dns: bool, nameservers: list<string>}
     */
    public function nameservers(string $sld, string $tld): array;

    /**
     * namecheap.domains.dns.getHosts.
     *
     * @return array{domain: string|null, using_namecheap_dns: bool, email_type: string|null, hosts: list<array{name: string, type: string, address: string, mx_pref: int|null, ttl: int|null}>}
     */
    public function hosts(string $sld, string $tld): array;

    /**
     * namecheap.domains.dns.setHosts. Replaces the complete host list of the domain.
     *
     * @param  list<array{name: string, type: string, address: string, mx_pref: int|null, ttl: int|null}>  $hosts
     */
    public function setHosts(string $sld, string $tld, array $hosts, ?string $emailType): void;
}
