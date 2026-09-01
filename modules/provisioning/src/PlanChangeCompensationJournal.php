<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use App\Agovena\Provisioning\ServiceInstanceInfo;
use BackedEnum;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnitEnum;

final class PlanChangeCompensationJournal
{
    private const TABLE = 'plan_change_compensation_journals';

    /**
     * @param  array<string, mixed>  $previousState
     */
    public function prepare(
        int $requestId,
        int $instanceId,
        string $providerKey,
        array $previousState,
        ServiceInstanceInfo $previousInfo,
        array $appliedState = [],
    ): string {
        $journalKey = (string) Str::uuid();
        $payload = [
            'phase' => 'prepared',
            'request_id' => $requestId,
            'instance_id' => $instanceId,
            'provider_key' => $providerKey,
            'previous_state' => $this->normalize($previousState),
            'applied_state' => $appliedState !== [] ? $this->normalize($appliedState) : null,
            'previous_info' => [
                'id' => $previousInfo->id,
                'label' => $previousInfo->label,
                'status' => $previousInfo->status,
                'provider_key' => $previousInfo->providerKey,
                'external_ref' => $previousInfo->externalRef,
                'meta' => $this->normalize($previousInfo->meta),
                'server_settings' => $this->normalize($previousInfo->serverSettings),
                'provider_settings' => $this->normalize($previousInfo->providerSettings),
            ],
            'created_at' => now()->toIso8601String(),
        ];
        $this->database()->table(self::TABLE)->insert([
            'journal_key' => $journalKey,
            'payload_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'phase' => 'prepared',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $journalKey;
    }

    public function markApplied(string $journalKey, ?string $claimToken = null): void
    {
        $entry = $this->read($journalKey);
        $entry['phase'] = 'applied';
        $entry['updated_at'] = now()->toIso8601String();
        $updated = $this->claimQuery($journalKey, $claimToken)
            ->update([
                'payload_encrypted' => Crypt::encryptString(json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'phase' => 'applied',
                'claim_token' => null,
                'claimed_at' => null,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to mark the plan-change compensation journal as applied.');
        }
    }

    public function forget(string $journalKey, ?string $claimToken = null): void
    {
        $deleted = $this->claimQuery($journalKey, $claimToken)->delete();
        if ($deleted !== 1) {
            throw new RuntimeException('Unable to delete the plan-change compensation journal.');
        }
    }

    public function claim(string $journalKey, int $leaseSeconds = 600): ?string
    {
        return $this->database()->transaction(function () use ($journalKey, $leaseSeconds): ?string {
            $entry = $this->database()->table(self::TABLE)
                ->where('journal_key', $journalKey)
                ->lockForUpdate()
                ->first();
            if ($entry === null || ! in_array($entry->phase, ['prepared', 'applied'], true)) {
                return null;
            }

            if ($entry->claimed_at !== null && now()->lessThan($entry->claimed_at)) {
                return null;
            }

            $token = (string) Str::uuid();
            $this->database()->table(self::TABLE)
                ->where('id', $entry->id)
                ->update([
                    'claim_token' => $token,
                    'claimed_at' => now()->addSeconds(max(1, $leaseSeconds)),
                    'updated_at' => now(),
                ]);

            return $token;
        });
    }

    public function markManualReview(string $journalKey, string $reason, ?string $claimToken = null): void
    {
        try {
            $entry = $this->read($journalKey);
            $entry['phase'] = 'manual_review';
            $entry['recovery_reason'] = $reason;
            $entry['updated_at'] = now()->toIso8601String();
            $updated = $this->claimQuery($journalKey, $claimToken)
                ->update([
                    'payload_encrypted' => Crypt::encryptString(json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                    'phase' => 'manual_review',
                    'claim_token' => null,
                    'claimed_at' => null,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Unable to mark the plan-change compensation journal for manual review.');
            }
        } catch (Throwable) {
            $updated = $this->claimQuery($journalKey, $claimToken)
                ->update([
                    'phase' => 'manual_review',
                    'claim_token' => null,
                    'claimed_at' => null,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Unable to mark the plan-change compensation journal for manual review.');
            }
        }
    }

    public function isClaimOwner(string $journalKey, string $claimToken): bool
    {
        return $this->database()->table(self::TABLE)
            ->where('journal_key', $journalKey)
            ->where('claim_token', $claimToken)
            ->where('claimed_at', '>', now())
            ->exists();
    }

    public function release(string $journalKey, string $claimToken): void
    {
        $this->database()->table(self::TABLE)
            ->where('journal_key', $journalKey)
            ->where('claim_token', $claimToken)
            ->update([
                'claim_token' => null,
                'claimed_at' => null,
                'updated_at' => now(),
            ]);
    }

    /** @return \Generator<int, array{path: string, payload: array<string, mixed>, invalid?: bool}> */
    public function entries(): \Generator
    {
        foreach ($this->database()->table(self::TABLE)->orderBy('id')->cursor() as $entry) {
            try {
                yield [
                    'path' => (string) $entry->journal_key,
                    'payload' => $this->decode((string) $entry->payload_encrypted),
                ];
            } catch (Throwable $exception) {
                Log::error('Plan-change compensation journal could not be read.', [
                    'exception' => $exception::class,
                ]);
                yield [
                    'path' => (string) $entry->journal_key,
                    'payload' => [],
                    'invalid' => true,
                ];
            }
        }
    }

    /** @return array<string, mixed> */
    public function read(string $journalKey): array
    {
        $entry = $this->database()->table(self::TABLE)
            ->where('journal_key', $journalKey)
            ->first();
        if ($entry === null) {
            throw new RuntimeException('Plan-change compensation journal does not exist.');
        }

        return $this->decode((string) $entry->payload_encrypted);
    }

    /** @return array<string, mixed> */
    private function decode(string $encrypted): array
    {
        $payload = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('Invalid plan-change compensation journal.');
        }

        return $payload;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item);
            }

            return $normalized;
        }

        return $value;
    }

    private function database(): Connection
    {
        $defaultConnection = (string) config('database.default');
        $defaultDatabase = config("database.connections.{$defaultConnection}.database");
        $isTestProcess = (app()->runningUnitTests() || app()->environment('testing') || config('app.env') === 'testing')
            && $defaultDatabase === ':memory:';
        if ($isTestProcess) {
            return DB::connection();
        }

        return DB::connection((string) config('database.compensation_connection', 'compensation_journal'));
    }

    private function claimQuery(string $journalKey, ?string $claimToken): Builder
    {
        $query = $this->database()->table(self::TABLE)->where('journal_key', $journalKey);
        if ($claimToken === null) {
            return $query->whereNull('claim_token');
        }

        return $query
            ->where('claim_token', $claimToken)
            ->where('claimed_at', '>', now());
    }
}
