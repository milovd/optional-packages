<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapDomain;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Namecheap XML API client (https://www.namecheap.com/support/api/methods/).
 *
 * Every command is a form POST to the xml.response endpoint. Namecheap reports failures
 * inside an HTTP 200 body as ApiResponse Status="ERROR", so only Status="OK" with the
 * expected command response is accepted. Redirects are never followed and neither the
 * API key nor provider error text ever reaches an exception message.
 */
final class HttpNamecheapApi implements NamecheapApi, NamecheapDnsApi
{
    private const PRODUCTION_URL = 'https://api.namecheap.com/xml.response';

    private const SANDBOX_URL = 'https://api.sandbox.namecheap.com/xml.response';

    private const TIMEOUT = 20;

    /** domains.create and domains.renew charge the account and can take around 30 seconds. */
    private const BILLABLE_TIMEOUT = 90;

    /** Documented "Domain not found" and "Domain is not associated with your account". */
    private const NOT_IN_ACCOUNT_ERRORS = ['2019166', '2016166'];

    private const PRICE_CACHE_SECONDS = 3600;

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
    ) {}

    public function check(array $domains): array
    {
        $xml = $this->request('namecheap.domains.check', ['DomainList' => implode(',', $domains)]);
        $results = [];
        foreach ($this->nodes($xml, 'DomainCheckResult') as $node) {
            $results[] = [
                'domain' => $this->lowerAttribute($node, 'Domain'),
                'available' => $this->booleanAttribute($node, 'Available'),
                'premium' => $this->booleanAttribute($node, 'IsPremiumName'),
                'error_no' => $this->attribute($node, 'ErrorNo'),
                'premium_registration_price' => $this->amountAttribute($node, 'PremiumRegistrationPrice'),
                'eap_fee' => $this->amountAttribute($node, 'EapFee'),
                'icann_fee' => $this->amountAttribute($node, 'IcannFee'),
            ];
        }

        return ['domains' => $results];
    }

    public function registrationPrice(string $tld, int $years): ?array
    {
        $tld = strtolower($tld);
        $cacheKey = 'namecheap-domain:register-price:'.sha1($this->endpoint().'|'.$this->setting('username').'|'.$tld.'|'.$years);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && is_string($cached['price'] ?? null) && is_string($cached['currency'] ?? null)) {
            return ['price' => $cached['price'], 'currency' => $cached['currency']];
        }

        $xml = $this->request('namecheap.users.getPricing', [
            'ProductType' => 'DOMAIN',
            'ActionName' => 'REGISTER',
            'ProductName' => strtoupper($tld),
        ]);
        $price = null;
        foreach ($this->nodes($xml, 'ProductCategory') as $category) {
            if (strtoupper((string) $this->attribute($category, 'Name')) !== 'REGISTER') {
                continue;
            }
            foreach ($this->children($category, 'Product') as $product) {
                if ($this->lowerAttribute($product, 'Name') !== $tld) {
                    continue;
                }
                foreach ($this->children($product, 'Price') as $node) {
                    $amount = (string) $this->attribute($node, 'Price');
                    $currency = strtoupper((string) $this->attribute($node, 'Currency'));
                    if ((int) $this->attribute($node, 'Duration') === $years
                        && strtoupper((string) $this->attribute($node, 'DurationType')) === 'YEAR'
                        && preg_match('/\A\d+(?:\.\d+)?\z/', $amount)
                        && preg_match('/\A[A-Z]{3}\z/', $currency)) {
                        $price = ['price' => $amount, 'currency' => $currency];
                    }
                }
            }
        }

        if ($price !== null) {
            Cache::put($cacheKey, $price, self::PRICE_CACHE_SECONDS);
        }

        return $price;
    }

    public function info(string $domain): ?array
    {
        try {
            $xml = $this->request('namecheap.domains.getInfo', ['DomainName' => $domain]);
        } catch (NamecheapRequestRejected $exception) {
            if (in_array($exception->errorNumber, self::NOT_IN_ACCOUNT_ERRORS, true)) {
                return null;
            }

            throw $exception;
        }

        $node = $this->firstNode($xml, 'DomainGetInfoResult');
        if ($this->lowerAttribute($node, 'DomainName') !== $domain) {
            throw new RuntimeException('Namecheap Registrar returned information for a different domain.');
        }

        return [
            'domain' => $domain,
            'domain_id' => $this->attribute($node, 'ID'),
            'is_owner' => $this->booleanAttribute($node, 'IsOwner'),
            'is_premium' => $this->booleanAttribute($node, 'IsPremium'),
            'status' => $this->attribute($node, 'Status'),
            'expires_at' => $this->expiryDate($node),
        ];
    }

    public function register(string $domain, int $years, array $contact): array
    {
        $parameters = ['DomainName' => $domain, 'Years' => $years];
        foreach (['Registrant', 'Tech', 'Admin', 'AuxBilling'] as $role) {
            foreach ($contact as $field => $value) {
                if ($value !== '') {
                    $parameters[$role.$field] = $value;
                }
            }
        }

        $node = $this->firstNode($this->request('namecheap.domains.create', $parameters, billable: true), 'DomainCreateResult', billable: true);

        return [
            'domain' => $this->lowerAttribute($node, 'Domain'),
            'registered' => $this->booleanAttribute($node, 'Registered'),
            'non_real_time' => $this->booleanAttribute($node, 'NonRealTimeDomain'),
            'domain_id' => $this->attribute($node, 'DomainID'),
            'order_id' => $this->attribute($node, 'OrderID'),
            'transaction_id' => $this->attribute($node, 'TransactionID'),
            'charged_amount' => $this->attribute($node, 'ChargedAmount'),
        ];
    }

    public function renew(string $domain, int $years): array
    {
        $xml = $this->request('namecheap.domains.renew', ['DomainName' => $domain, 'Years' => $years], billable: true);
        $node = $this->firstNode($xml, 'DomainRenewResult', billable: true);

        return [
            'domain' => $this->lowerAttribute($node, 'DomainName'),
            'renewed' => $this->booleanAttribute($node, 'Renew'),
            'domain_id' => $this->attribute($node, 'DomainID'),
            'order_id' => $this->attribute($node, 'OrderID'),
            'transaction_id' => $this->attribute($node, 'TransactionID'),
            'charged_amount' => $this->attribute($node, 'ChargedAmount'),
            'expires_at' => $this->expiryDate($node),
        ];
    }

    public function nameservers(string $sld, string $tld): array
    {
        $node = $this->firstNode($this->request('namecheap.domains.dns.getList', ['SLD' => $sld, 'TLD' => $tld]), 'DomainDNSGetListResult');
        $nameservers = [];
        foreach ($this->children($node, 'Nameserver') as $nameserver) {
            $value = strtolower(trim((string) $nameserver));
            if ($value !== '') {
                $nameservers[] = $value;
            }
        }

        return [
            'domain' => $this->lowerAttribute($node, 'Domain'),
            'using_namecheap_dns' => $this->booleanAttribute($node, 'IsUsingOurDNS'),
            'nameservers' => $nameservers,
        ];
    }

    public function hosts(string $sld, string $tld): array
    {
        $node = $this->firstNode($this->request('namecheap.domains.dns.getHosts', ['SLD' => $sld, 'TLD' => $tld]), 'DomainDNSGetHostsResult');
        $hosts = [];
        foreach ($this->children($node, 'host') as $host) {
            $name = $this->attribute($host, 'Name');
            $type = $this->attribute($host, 'Type');
            $address = $this->attribute($host, 'Address');
            if ($name === null || $type === null || $address === null) {
                throw new RuntimeException('Namecheap Registrar returned an incomplete DNS host record.');
            }
            $hosts[] = [
                'name' => $name,
                'type' => strtoupper($type),
                'address' => $address,
                'mx_pref' => $this->integerAttribute($host, 'MXPref'),
                'ttl' => $this->integerAttribute($host, 'TTL'),
            ];
        }

        return [
            'domain' => $this->lowerAttribute($node, 'Domain'),
            'using_namecheap_dns' => $this->booleanAttribute($node, 'IsUsingOurDNS'),
            'email_type' => $this->attribute($node, 'EmailType'),
            'hosts' => $hosts,
        ];
    }

    public function setHosts(string $sld, string $tld, array $hosts, ?string $emailType): void
    {
        $parameters = ['SLD' => $sld, 'TLD' => $tld];
        if ($emailType !== null) {
            $parameters['EmailType'] = $emailType;
        }
        foreach ($hosts as $index => $host) {
            $number = $index + 1;
            $parameters['HostName'.$number] = $host['name'];
            $parameters['RecordType'.$number] = $host['type'];
            $parameters['Address'.$number] = $host['address'];
            if ($host['type'] === 'MX' && $host['mx_pref'] !== null) {
                $parameters['MXPref'.$number] = $host['mx_pref'];
            }
            if ($host['ttl'] !== null) {
                $parameters['TTL'.$number] = $host['ttl'];
            }
        }

        $node = $this->firstNode($this->request('namecheap.domains.dns.setHosts', $parameters), 'DomainDNSSetHostsResult');
        if (! $this->booleanAttribute($node, 'IsSuccess') || $this->lowerAttribute($node, 'Domain') !== strtolower($sld.'.'.$tld)) {
            throw new RuntimeException('Namecheap Registrar did not confirm the DNS host update.');
        }
    }

    /** @param array<string, scalar> $parameters */
    private function request(string $command, array $parameters, bool $billable = false): SimpleXMLElement
    {
        if (app()->environment('demo')) {
            throw new RuntimeException('Namecheap Registrar requests are disabled in the demo environment.');
        }

        $payload = array_merge($parameters, $this->credentials(), ['Command' => $command]);

        try {
            $response = Http::asForm()
                ->accept('application/xml')
                ->withoutRedirecting()
                ->timeout($billable ? self::BILLABLE_TIMEOUT : self::TIMEOUT)
                ->post($this->endpoint(), $payload);
        } catch (Throwable) {
            // The transport exception is dropped on purpose: its message can contain request data.
            throw $this->failure($billable, 'Namecheap Registrar request failed.');
        }

        if (! $response->successful()) {
            throw $this->failure($billable, 'Namecheap Registrar returned an unsuccessful response.');
        }

        $xml = $this->parse($response->body());
        if ($xml === null || $xml->getName() !== 'ApiResponse') {
            throw $this->failure($billable, 'Namecheap Registrar returned an invalid response.');
        }

        $status = strtoupper((string) $this->attribute($xml, 'Status'));
        if ($status === 'ERROR') {
            $number = $this->attribute($this->nodes($xml, 'Error')[0] ?? $xml, 'Number');

            throw new NamecheapRequestRejected($number !== null && ctype_digit($number) ? $number : null);
        }

        $commandResponse = $this->nodes($xml, 'CommandResponse')[0] ?? null;
        $type = $commandResponse !== null ? $this->attribute($commandResponse, 'Type') : null;
        if ($status !== 'OK' || $commandResponse === null || ($type !== null && strcasecmp($type, $command) !== 0)) {
            throw $this->failure($billable, 'Namecheap Registrar returned an invalid response.');
        }

        return $xml;
    }

    /** @return array<string, string> */
    private function credentials(): array
    {
        $credentials = [
            'ApiUser' => $this->setting('api_user'),
            'ApiKey' => $this->setting('api_key'),
            'UserName' => $this->setting('username'),
            'ClientIp' => $this->setting('client_ip'),
        ];
        if (in_array('', $credentials, true) || filter_var($credentials['ClientIp'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Namecheap Registrar is not configured.');
        }

        return $credentials;
    }

    private function setting(string $key): string
    {
        return trim((string) $this->settings->get('namecheap-domain', $key, ''));
    }

    private function endpoint(): string
    {
        return filter_var($this->settings->get('namecheap-domain', 'sandbox', true), FILTER_VALIDATE_BOOLEAN)
            ? self::SANDBOX_URL
            : self::PRODUCTION_URL;
    }

    /**
     * A billable command whose result is not a definitive answer may still have been
     * executed, so the caller must reconcile before submitting it again.
     */
    private function failure(bool $billable, string $message): RuntimeException
    {
        return new RuntimeException($billable
            ? 'Namecheap Registrar request outcome is unknown; reconcile the domain before retrying.'
            : $message);
    }

    private function parse(string $body): ?SimpleXMLElement
    {
        if (trim($body) === '' || stripos($body, '<!DOCTYPE') !== false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml instanceof SimpleXMLElement ? $xml : null;
    }

    /** @return list<SimpleXMLElement> */
    private function nodes(SimpleXMLElement $xml, string $name): array
    {
        return array_values($xml->xpath('//*[local-name()="'.$name.'"]') ?: []);
    }

    /**
     * Direct children by case-insensitive local name (the live API sends <host>, the docs show <Host>).
     *
     * @return list<SimpleXMLElement>
     */
    private function children(SimpleXMLElement $node, string $name): array
    {
        // xpath() keeps plain attribute access working on elements in the default namespace.
        return array_values(array_filter(
            $node->xpath('./*') ?: [],
            static fn (SimpleXMLElement $child): bool => strcasecmp($child->getName(), $name) === 0,
        ));
    }

    private function firstNode(SimpleXMLElement $xml, string $name, bool $billable = false): SimpleXMLElement
    {
        return $this->nodes($xml, $name)[0]
            ?? throw $this->failure($billable, 'Namecheap Registrar returned an incomplete response.');
    }

    private function expiryDate(SimpleXMLElement $node): ?string
    {
        $details = $this->children($node, 'DomainDetails')[0] ?? null;
        $expiry = $details !== null ? ($this->children($details, 'ExpiredDate')[0] ?? null) : null;
        if ($expiry === null || ! preg_match('#\A(\d{1,2})/(\d{1,2})/(\d{4})\b#', trim((string) $expiry), $match)
            || ! checkdate((int) $match[1], (int) $match[2], (int) $match[3])) {
            return null;
        }

        return CarbonImmutable::create((int) $match[3], (int) $match[1], (int) $match[2], 0, 0, 0, 'UTC')->toIso8601String();
    }

    private function attribute(SimpleXMLElement $node, string $name): ?string
    {
        $value = trim((string) ($node[$name] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function lowerAttribute(SimpleXMLElement $node, string $name): ?string
    {
        $value = $this->attribute($node, $name);

        return $value !== null ? strtolower($value) : null;
    }

    private function booleanAttribute(SimpleXMLElement $node, string $name): bool
    {
        return strtolower((string) $this->attribute($node, $name)) === 'true';
    }

    private function integerAttribute(SimpleXMLElement $node, string $name): ?int
    {
        $value = $this->attribute($node, $name);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    /** Zero amounts mean "not applicable"; anything else, including unparseable values, is kept. */
    private function amountAttribute(SimpleXMLElement $node, string $name): ?string
    {
        $value = $this->attribute($node, $name);

        return $value === null || (is_numeric($value) && (float) $value === 0.0) ? null : $value;
    }
}
