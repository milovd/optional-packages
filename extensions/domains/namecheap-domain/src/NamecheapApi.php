<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapDomain;

/**
 * Registrar commands of the Namecheap XML API. Every method throws a RuntimeException
 * when the request fails; NamecheapRequestRejected carries the documented error number.
 */
interface NamecheapApi
{
    /**
     * namecheap.domains.check. Zero premium, EAP and ICANN amounts are returned as null.
     *
     * @param  list<string>  $domains
     * @return array{domains: list<array{domain: string|null, available: bool, premium: bool, error_no: string|null, premium_registration_price: string|null, eap_fee: string|null, icann_fee: string|null}>}
     */
    public function check(array $domains): array;

    /**
     * namecheap.users.getPricing for ProductType DOMAIN and ActionName REGISTER (cached).
     *
     * @return array{price: string, currency: string}|null
     */
    public function registrationPrice(string $tld, int $years): ?array;

    /**
     * namecheap.domains.getInfo; null when Namecheap reports the domain is not in this account.
     *
     * @return array{domain: string|null, domain_id: string|null, is_owner: bool, is_premium: bool, status: string|null, expires_at: string|null}|null
     */
    public function info(string $domain): ?array;

    /**
     * namecheap.domains.create with one contact for the Registrant, Tech, Admin and AuxBilling roles.
     *
     * @param  array<string, string>  $contact
     * @return array{domain: string|null, registered: bool, non_real_time: bool, domain_id: string|null, order_id: string|null, transaction_id: string|null, charged_amount: string|null}
     */
    public function register(string $domain, int $years, array $contact): array;

    /**
     * namecheap.domains.renew.
     *
     * @return array{domain: string|null, renewed: bool, domain_id: string|null, order_id: string|null, transaction_id: string|null, charged_amount: string|null, expires_at: string|null}
     */
    public function renew(string $domain, int $years): array;
}
