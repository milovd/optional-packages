<?php

declare(strict_types=1);

namespace Agovena\Extensions\Plesk;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;
use Agovena\Modules\Provisioning\Support\ServerProviderException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Plesk XML API client. Every packet is POSTed to /enterprise/control/agent.php with the
 * secret key in the KEY header. HTTP 200 proves nothing: each result status is checked and
 * any unexpected shape fails closed.
 *
 * @phpstan-import-type PleskSubscription from PleskApi
 */
final class HttpPleskApi extends AbstractHttpServerApi implements PleskApi
{
    private const AGENT_PATH = '/enterprise/control/agent.php';

    private const ERROR_AUTHENTICATION = 1001;

    private const ERROR_NOT_FOUND = 1013;

    private const SUBSCRIPTION_DATASET = '<dataset><gen_info/><subscriptions/></dataset>';

    /** @return array<string, mixed> */
    public function connectionTest(): array
    {
        $this->single('server', 'get_protos', '');

        return ['status' => 'ok'];
    }

    /** @return array<string, string> */
    public function servicePlans(): array
    {
        $plans = [];
        foreach ($this->call('service-plan', 'get', '<filter/>') as $result) {
            $this->assertOk($result);
            $name = $this->text($result, 'name');
            $guid = $this->text($result, 'guid');
            if ($name === null || $guid === null || isset($plans[$name])) {
                throw new ServerProviderException('errors.malformed');
            }
            $plans[$name] = $guid;
        }

        return $plans;
    }

    public function customerByLogin(string $login): int
    {
        $result = $this->single('customer', 'get', $this->filter('login', $login).'<dataset><gen_info/></dataset>');
        if ($this->text($result, 'data/gen_info/login') !== $login) {
            throw new ServerProviderException('errors.malformed');
        }

        return $this->id($result);
    }

    /** @param array{login: string, name: string, password: string, email: string|null} $customer */
    public function addCustomer(array $customer): int
    {
        $genInfo = $this->element('pname', $customer['name'])
            .$this->element('login', $customer['login'])
            .$this->element('passwd', $customer['password'])
            .($customer['email'] === null ? '' : $this->element('email', $customer['email']));

        return $this->id($this->single('customer', 'add', '<gen_info>'.$genInfo.'</gen_info>'));
    }

    public function deleteCustomer(int $customerId): void
    {
        $this->expectId($this->single('customer', 'del', $this->filter('id', (string) $customerId)), $customerId);
    }

    /** @param array{name: string, owner_id: int, ip: string, plan: string, ftp_login: string, ftp_password: string} $subscription */
    public function addSubscription(array $subscription): int
    {
        $genSetup = $this->element('name', $subscription['name'])
            .$this->element('owner-id', (string) $subscription['owner_id'])
            .$this->element('htype', 'vrt_hst')
            .$this->element('ip_address', $subscription['ip']);
        $hosting = $this->property('ftp_login', $subscription['ftp_login'])
            .$this->property('ftp_password', $subscription['ftp_password'])
            .$this->element('ip_address', $subscription['ip']);

        return $this->id($this->single('webspace', 'add', '<gen_setup>'.$genSetup.'</gen_setup>'
            .'<hosting><vrt_hst>'.$hosting.'</vrt_hst></hosting>'
            .$this->element('plan-name', $subscription['plan'])));
    }

    /** @return PleskSubscription */
    public function subscriptionByName(string $name): array
    {
        $subscription = $this->subscriptionFrom($this->single('webspace', 'get', $this->filter('name', $name).self::SUBSCRIPTION_DATASET));
        if (strtolower($subscription['name']) !== strtolower($name)) {
            throw new ServerProviderException('errors.malformed');
        }

        return $subscription;
    }

    /** @return PleskSubscription */
    public function subscription(int $subscriptionId): array
    {
        $result = $this->single('webspace', 'get', $this->filter('id', (string) $subscriptionId).self::SUBSCRIPTION_DATASET);
        $this->expectId($result, $subscriptionId);

        return $this->subscriptionFrom($result);
    }

    public function setSubscriptionStatus(int $subscriptionId, int $status): void
    {
        $values = '<values><gen_setup>'.$this->element('status', (string) $status).'</gen_setup></values>';
        $this->expectId($this->single('webspace', 'set', $this->filter('id', (string) $subscriptionId).$values), $subscriptionId);
    }

    public function deleteSubscription(int $subscriptionId): void
    {
        $this->expectId($this->single('webspace', 'del', $this->filter('id', (string) $subscriptionId)), $subscriptionId);
    }

    public function switchPlan(int $subscriptionId, string $planGuid): void
    {
        $body = $this->filter('id', (string) $subscriptionId).$this->element('plan-guid', $planGuid);
        $this->expectId($this->single('webspace', 'switch-subscription', $body), $subscriptionId);
    }

    /** @return list<int> */
    public function subscriptionsOwnedBy(int $customerId): array
    {
        $ids = [];
        foreach ($this->call('webspace', 'get', $this->filter('owner-id', (string) $customerId).'<dataset><gen_info/></dataset>') as $result) {
            $this->assertOk($result);
            if ($this->text($result, 'id') !== null) {
                $ids[] = $this->id($result);
            }
        }

        return $ids;
    }

    /** @return PleskSubscription */
    private function subscriptionFrom(DOMElement $result): array
    {
        $name = $this->text($result, 'data/gen_info/name');
        $status = (string) $this->text($result, 'data/gen_info/status');
        $owner = (string) $this->text($result, 'data/gen_info/owner-id');
        if ($name === null || ! ctype_digit($status) || ! ctype_digit($owner)) {
            throw new ServerProviderException('errors.malformed');
        }

        $planGuids = [];
        foreach ($this->nodes($result, 'data/subscriptions/subscription/plan/plan-guid') as $node) {
            $planGuids[] = trim($node->textContent);
        }

        return ['id' => $this->id($result), 'name' => $name, 'status' => (int) $status, 'owner_id' => (int) $owner, 'plan_guids' => $planGuids];
    }

    /** The one result of an operation on a single object; any error status throws. */
    private function single(string $operator, string $operation, string $body): DOMElement
    {
        $results = $this->call($operator, $operation, $body);
        if (count($results) !== 1) {
            throw new ServerProviderException('errors.malformed');
        }
        $this->assertOk($results[0]);

        return $results[0];
    }

    /** @return list<DOMElement> */
    private function call(string $operator, string $operation, string $body): array
    {
        $content = $body === '' ? '<'.$operation.'/>' : '<'.$operation.'>'.$body.'</'.$operation.'>';
        $packet = $this->parse($this->send('<?xml version="1.0" encoding="UTF-8"?><packet><'.$operator.'>'.$content.'</'.$operator.'></packet>'));

        // A system node reports a packet-level failure that no operation result describes.
        $system = $this->nodes($packet, 'system');
        if ($system !== []) {
            $code = (int) $this->text($system[0], 'errcode');
            throw new ServerProviderException($code === self::ERROR_AUTHENTICATION ? 'errors.unauthorized' : 'errors.provider_failed');
        }

        $nodes = $this->nodes($packet, $operator.'/'.$operation);
        if (count($nodes) !== 1) {
            throw new ServerProviderException('errors.malformed');
        }

        return $this->nodes($nodes[0], 'result');
    }

    private function assertOk(DOMElement $result): void
    {
        $status = $this->text($result, 'status');
        if ($status === 'ok') {
            return;
        }
        if ($status !== 'error') {
            throw new ServerProviderException('errors.malformed');
        }

        throw new ServerProviderException(match ((int) $this->text($result, 'errcode')) {
            self::ERROR_NOT_FOUND => 'errors.not_found',
            self::ERROR_AUTHENTICATION => 'errors.unauthorized',
            default => 'errors.rejected',
        });
    }

    private function id(DOMElement $result): int
    {
        $id = (string) $this->text($result, 'id');
        if (! ctype_digit($id) || (int) $id < 1) {
            throw new ServerProviderException('errors.malformed');
        }

        return (int) $id;
    }

    private function expectId(DOMElement $result, int $expected): void
    {
        if ($this->id($result) !== $expected) {
            throw new ServerProviderException('errors.malformed');
        }
    }

    /** Trimmed text of exactly one element at the relative path, or null. */
    private function text(DOMElement $context, string $path): ?string
    {
        $nodes = $this->nodes($context, $path);
        $text = count($nodes) === 1 ? trim($nodes[0]->textContent) : '';

        return $text === '' ? null : $text;
    }

    /** @return list<DOMElement> */
    private function nodes(DOMElement $context, string $path): array
    {
        $document = $context->ownerDocument ?? throw new ServerProviderException('errors.malformed');
        $elements = [];
        foreach ((new DOMXPath($document))->query($path, $context) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /** Parses without network access or DTD processing, so entities are never expanded. */
    private function parse(string $body): DOMElement
    {
        if (trim($body) === '' || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            throw new ServerProviderException('errors.malformed');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($body, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $packet = $document->documentElement;
        if ($loaded !== true || $document->doctype !== null || $packet === null || $packet->nodeName !== 'packet') {
            throw new ServerProviderException('errors.malformed');
        }

        return $packet;
    }

    private function send(string $packet): string
    {
        if (app()->environment('demo')) {
            throw new ServerProviderException('errors.demo_disabled');
        }
        $key = trim((string) ($this->connection['api_token'] ?? ''));
        if ($key === '') {
            throw new ServerProviderException('errors.not_configured');
        }

        $endpoint = PleskEndpoint::normalize((string) ($this->connection['api_url'] ?? ''));
        $verifyTls = filter_var($this->connection['verify_tls'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verifyTls === null) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        $options = ['verify' => $verifyTls];
        try {
            $this->urlValidator->validate($endpoint);
            $this->urlValidator->assertTlsVerification($verifyTls);
            if (! in_array(config('app.env'), ['local', 'testing'], true)) {
                $options['curl'] = [CURLOPT_RESOLVE => [$this->pinnedHost($endpoint)]];
            }
        } catch (ValidationException) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        try {
            $response = Http::timeout(max(1, (int) ($this->connection['timeout'] ?? 20)))
                ->withHeaders(['KEY' => $key])
                ->withOptions($options)
                ->withoutRedirecting()
                ->withBody($packet, 'text/xml')
                ->post($endpoint.self::AGENT_PATH);
        } catch (ConnectionException) {
            throw new ServerProviderException('errors.timeout');
        } catch (Throwable) {
            throw new ServerProviderException('errors.unreachable');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new ServerProviderException('errors.unauthorized', $response->status());
        }
        if ($response->status() !== 200) {
            throw new ServerProviderException('errors.provider_failed', $response->status());
        }

        return $response->body();
    }

    private function pinnedHost(string $endpoint): string
    {
        $parts = (array) parse_url($endpoint);
        $address = $this->urlValidator->resolvePublicIp($endpoint);
        $resolved = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.$address.']' : $address;

        return ($parts['host'] ?? '').':'.($parts['port'] ?? '').':'.$resolved;
    }

    private function filter(string $field, string $value): string
    {
        return '<filter>'.$this->element($field, $value).'</filter>';
    }

    private function property(string $name, string $value): string
    {
        return '<property>'.$this->element('name', $name).$this->element('value', $value).'</property>';
    }

    private function element(string $name, string $value): string
    {
        return '<'.$name.'>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</'.$name.'>';
    }
}
