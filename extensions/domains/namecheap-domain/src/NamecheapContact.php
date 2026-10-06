<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapDomain;

use Agovena\Modules\Domains\Models\DomainRegistration;
use App\Models\CustomerAddress;
use RuntimeException;

/**
 * Builds the documented domains.create contact fields (Registrant, Tech, Admin and
 * AuxBilling share them) from the customer's billing address. Validation runs before
 * any provider call; messages name the failing field and never echo customer data.
 */
final class NamecheapContact
{
    /** Documented MaxLength per field. */
    private const MAX_LENGTHS = [
        'FirstName' => 255,
        'LastName' => 255,
        'OrganizationName' => 255,
        'Address1' => 255,
        'Address2' => 255,
        'City' => 50,
        'StateProvince' => 50,
        'PostalCode' => 50,
        'EmailAddress' => 255,
    ];

    /** @return array<string, string> */
    public static function fromRegistration(DomainRegistration $registration): array
    {
        $address = $registration->customer_id === null ? null : CustomerAddress::query()
            ->where('customer_id', $registration->customer_id)
            ->orderByDesc('is_default_billing')
            ->orderBy('id')
            ->first();
        if ($address === null) {
            throw self::invalid('address');
        }

        $name = explode(' ', trim((string) preg_replace('/\s+/', ' ', $address->name)), 2);
        if (count($name) !== 2) {
            throw self::invalid('name (first and last name)');
        }

        $country = strtoupper(trim($address->country));
        if (! preg_match('/\A[A-Z]{2}\z/', $country)) {
            throw self::invalid('country (ISO 3166-1 alpha-2 code)');
        }

        $phone = self::phone((string) $address->phone);
        if ($phone === null) {
            throw self::invalid('phone (international format +<country code> <number>)');
        }

        $email = trim((string) ($registration->customer_email ?: $address->customer?->email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw self::invalid('email');
        }

        $contact = [
            'FirstName' => $name[0],
            'LastName' => $name[1],
            'OrganizationName' => trim((string) $address->company),
            'Address1' => trim($address->line1),
            'Address2' => trim((string) $address->line2),
            'City' => trim($address->city),
            'StateProvince' => trim((string) $address->region),
            'PostalCode' => trim($address->postal_code),
            'Country' => $country,
            'Phone' => $phone,
            'EmailAddress' => $email,
        ];
        foreach (['Address1' => 'address', 'City' => 'city', 'StateProvince' => 'region', 'PostalCode' => 'postal code'] as $field => $label) {
            if ($contact[$field] === '') {
                throw self::invalid($label);
            }
        }
        foreach (self::MAX_LENGTHS as $field => $maxLength) {
            if (mb_strlen($contact[$field]) > $maxLength) {
                throw self::invalid($field);
            }
        }

        return $contact;
    }

    /** Namecheap documents phone numbers as +NNN.NNNNNNNNNN. */
    private static function phone(string $phone): ?string
    {
        if (! preg_match('/\A\+(\d{1,3})[\s.\-]+([\d\s.\-()]+)\z/', trim($phone), $match)) {
            return null;
        }
        $number = (string) preg_replace('/\D/', '', $match[2]);

        return strlen($number) >= 4 && strlen($number) <= 14 ? '+'.$match[1].'.'.$number : null;
    }

    private static function invalid(string $field): RuntimeException
    {
        return new RuntimeException('The registrant contact for Namecheap is incomplete or invalid: '.$field.'.');
    }
}
