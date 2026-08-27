<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapDomain;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

final class HttpNamecheapApi implements NamecheapApi
{
    private const PRODUCTION_URL = 'https://api.namecheap.com/xml.response';
    private const SANDBOX_URL = 'https://api.sandbox.namecheap.com/xml.response';

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
    ) {}

    public function check(array $domains): array
    {
        $xml = $this->request('namecheap.domains.check', [
            'DomainList' => implode(',', array_values($domains)),
        ]);
        $result = ['domains' => []];
        foreach ($this->nodes($xml, 'DomainCheckResult') as $node) {
            $result['domains'][] = [
                'domain' => $this->attribute($node, 'Domain'),
                'available' => $this->booleanAttribute($node, 'Available'),
                'registration_price' => $this->firstAttribute($node, ['PremiumRegistrationPrice', 'RegistrationPrice']),
                'currency' => $this->firstAttribute($node, ['Currency', 'CurrencyCode']),
            ];
        }

        return $result;
    }

    public function register(string $domain, int $years): array
    {
        $xml = $this->request('namecheap.domains.create', [
            'DomainName' => $domain,
            'Years' => $years,
        ]);
        $node = $this->firstNode($xml, 'DomainCreateResult');

        return [
            'domain' => $this->attribute($node, 'Domain'),
            'registered' => $this->booleanAttribute($node, 'Registered'),
            'domain_id' => $this->attribute($node, 'DomainID'),
            'order_id' => $this->attribute($node, 'OrderID'),
            'transaction_id' => $this->attribute($node, 'TransactionID'),
            'charged_amount' => $this->attribute($node, 'ChargedAmount'),
        ];
    }

    public function renew(string $domain, int $years): array
    {
        $xml = $this->request('namecheap.domains.renew', [
            'DomainName' => $domain,
            'Years' => $years,
        ]);
        $node = $this->firstNode($xml, 'DomainRenewResult');

        return [
            'domain' => $this->attribute($node, 'DomainName'),
            'renewed' => $this->booleanAttribute($node, 'Renew'),
            'domain_id' => $this->attribute($node, 'DomainID'),
            'order_id' => $this->attribute($node, 'OrderID'),
            'transaction_id' => $this->attribute($node, 'TransactionID'),
            'charged_amount' => $this->attribute($node, 'ChargedAmount'),
        ];
    }

    /** @param array<string, scalar> $parameters */
    private function request(string $command, array $parameters): SimpleXMLElement
    {
        $apiUser = trim((string) $this->settings->get('namecheap-domain', 'api_user', ''));
        $apiKey = trim((string) $this->settings->get('namecheap-domain', 'api_key', ''));
        $username = trim((string) $this->settings->get('namecheap-domain', 'username', ''));
        $clientIp = trim((string) $this->settings->get('namecheap-domain', 'client_ip', ''));
        if ($apiUser === '' || $apiKey === '' || $username === '' || $clientIp === '') {
            throw new RuntimeException('Namecheap Registrar is not configured.');
        }

        $payload = array_merge([
            'ApiUser' => $apiUser,
            'ApiKey' => $apiKey,
            'UserName' => $username,
            'Command' => $command,
            'ClientIp' => $clientIp,
        ], $parameters);
        $baseUrl = filter_var($this->settings->get('namecheap-domain', 'sandbox', true), FILTER_VALIDATE_BOOLEAN)
            ? self::SANDBOX_URL
            : self::PRODUCTION_URL;

        try {
            $response = Http::asForm()->accept('application/xml')->timeout(15)->post($baseUrl, $payload);
            if (! $response->successful()) {
                throw new RuntimeException('Namecheap Registrar returned an unsuccessful response.');
            }
            $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if (! $xml instanceof SimpleXMLElement || strtoupper((string) $xml['Status']) !== 'OK') {
                throw new RuntimeException('Namecheap Registrar rejected the request.');
            }
            if ($this->nodes($xml, 'Error') !== []) {
                throw new RuntimeException('Namecheap Registrar rejected the request.');
            }

            return $xml;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && $exception->getMessage() !== 'Namecheap Registrar request failed.') {
                throw $exception;
            }

            throw new RuntimeException('Namecheap Registrar request failed.', previous: $exception);
        }
    }

    /** @return list<SimpleXMLElement> */
    private function nodes(SimpleXMLElement $xml, string $name): array
    {
        $nodes = $xml->xpath('//*[local-name()="'.$name.'"]') ?: [];

        return array_values(array_filter($nodes, static fn (mixed $node): bool => $node instanceof SimpleXMLElement));
    }

    private function firstNode(SimpleXMLElement $xml, string $name): SimpleXMLElement
    {
        $node = $this->nodes($xml, $name)[0] ?? null;
        if (! $node instanceof SimpleXMLElement) {
            throw new RuntimeException('Namecheap Registrar returned an incomplete response.');
        }

        return $node;
    }

    private function attribute(SimpleXMLElement $node, string $name): ?string
    {
        $value = (string) ($node[$name] ?? '');

        return $value !== '' ? $value : null;
    }

    /** @param list<string> $names */
    private function firstAttribute(SimpleXMLElement $node, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $this->attribute($node, $name);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function booleanAttribute(SimpleXMLElement $node, string $name): bool
    {
        return filter_var($this->attribute($node, $name), FILTER_VALIDATE_BOOLEAN);
    }
}
