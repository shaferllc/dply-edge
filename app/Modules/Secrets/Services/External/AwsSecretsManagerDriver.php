<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services\External;

use App\Models\ExternalSecretStore;
use Aws\SecretsManager\SecretsManagerClient;
use RuntimeException;

/**
 * AWS Secrets Manager. config: {region, key?, secret?}. Omit key/secret to use
 * the box's own IAM/instance credentials (the SDK default chain) — the natural
 * fit for resolution=onbox. Reference: "<secretId>#<field>"; with no field the
 * raw SecretString is returned, otherwise it is JSON-decoded and the field read.
 */
class AwsSecretsManagerDriver extends AbstractSecretStoreDriver
{
    public function fetch(ExternalSecretStore $store, string $reference): string
    {
        $cfg = (array) $store->config;
        $region = (string) ($cfg['region'] ?? '');
        if ($region === '') {
            throw new RuntimeException('AWS Secrets Manager store is missing a region.');
        }

        [$secretId, $field] = self::splitReference($reference);
        if ($secretId === '') {
            throw new RuntimeException('AWS Secrets Manager reference is missing a secret id.');
        }

        $args = ['version' => 'latest', 'region' => $region];
        if (! empty($cfg['key']) && ! empty($cfg['secret'])) {
            $args['credentials'] = ['key' => (string) $cfg['key'], 'secret' => (string) $cfg['secret']];
        }

        $result = $this->client($args)->getSecretValue(['SecretId' => $secretId]);
        $secretString = $result['SecretString'] ?? null;
        if (! is_string($secretString)) {
            throw new RuntimeException("AWS secret '{$secretId}' has no string value.");
        }

        if ($field === null) {
            return $secretString;
        }

        $decoded = json_decode($secretString, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("AWS secret '{$secretId}' is not JSON — cannot read field '{$field}'.");
        }

        return $this->pickField($decoded, $field, "aws_sm:{$secretId}");
    }

    /**
     * Isolated for test substitution.
     *
     * @param  array<string, mixed>  $args
     */
    protected function client(array $args): SecretsManagerClient
    {
        return new SecretsManagerClient($args);
    }
}
