<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services;

use App\Models\OrgSecretKey;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The only crypto seam. Shells out to the pinned `age` binary — identical to
 * what the bash guards use — so ciphertext is interchangeable between the PHP
 * and shell paths.
 *
 * Encryption needs only the PUBLIC recipients file (safe on every box).
 * Decryption needs the OFFLINE private identity and is expected to be
 * impossible in normal prod — that is the asymmetric guarantee.
 */
final class AgeEncryptor
{
    public function __construct(
        private readonly string $ageBin,
        private readonly string $recipientsPath,
        private readonly ?string $identityPath = null,
        private readonly string $keygenBin = 'age-keygen',
    ) {}

    public function encrypt(string $plaintext): string
    {
        if (! is_file($this->recipientsPath)) {
            throw new RuntimeException("age recipients file not found: {$this->recipientsPath}");
        }

        $result = Process::input($plaintext)
            ->timeout(120)
            ->run([$this->ageBin, '-e', '-R', $this->recipientsPath]);

        if (! $result->successful()) {
            throw new RuntimeException('age encrypt failed: '.trim($result->errorOutput()));
        }

        return $result->output();
    }

    public function decrypt(string $ciphertext): string
    {
        if ($this->identityPath === null || ! is_file($this->identityPath)) {
            throw new RuntimeException(
                'age identity not available — restore/verify requires the offline private key (SECRET_VAULT_IDENTITY_PATH).'
            );
        }

        $result = Process::input($ciphertext)
            ->timeout(120)
            ->run([$this->ageBin, '-d', '-i', $this->identityPath]);

        if (! $result->successful()) {
            throw new RuntimeException('age decrypt failed: '.trim($result->errorOutput()));
        }

        return $result->output();
    }

    public function canDecrypt(): bool
    {
        return $this->identityPath !== null && is_file($this->identityPath);
    }

    /**
     * Encrypt to one or more explicit recipient strings (`age1...`) rather than
     * the platform recipients file. Used for per-org keys, where the recipient
     * lives in the DB ({@see OrgSecretKey::$public_recipient}), not
     * on the box. The platform DR path keeps using {@see encrypt()}.
     *
     * ASCII-armored (`-a`) so the ciphertext is text-safe for a DB column; the
     * platform DR path ({@see encrypt()}) stays binary because it writes to
     * object storage. `age -d` auto-detects armor, so decryption needs no flag.
     *
     * @param  array<int, string>  $recipients
     */
    public function encryptTo(string $plaintext, array $recipients): string
    {
        $recipients = array_values(array_filter(array_map('trim', $recipients), fn (string $r): bool => $r !== ''));
        if ($recipients === []) {
            throw new RuntimeException('encryptTo requires at least one recipient.');
        }

        $args = [$this->ageBin, '-e', '-a'];
        foreach ($recipients as $recipient) {
            $args[] = '-r';
            $args[] = $recipient;
        }

        $result = Process::input($plaintext)->timeout(120)->run($args);
        if (! $result->successful()) {
            throw new RuntimeException('age encrypt (per-recipient) failed: '.trim($result->errorOutput()));
        }

        return $result->output();
    }

    /**
     * Decrypt with an identity supplied as a STRING (the `AGE-SECRET-KEY-...`
     * material, optionally with `# ...` comment lines age ignores) rather than a
     * file path. The identity is written to a private (0600) temp file for the
     * lifetime of the call only and removed in a finally — it never persists.
     *
     * This is how both dply-held per-org decryption (identity from
     * OrgSecretKey.dply_identity) and customer-held decryption (ephemeral
     * identity supplied for one deploy) run, without ever placing the key on the
     * box's filesystem beyond the call.
     */
    public function decryptWith(string $ciphertext, string $identity): string
    {
        if (trim($identity) === '') {
            throw new RuntimeException('decryptWith requires a non-empty identity.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dply-age-id-');
        if ($tmp === false) {
            throw new RuntimeException('could not create a temp file for the age identity.');
        }

        try {
            chmod($tmp, 0600);
            if (file_put_contents($tmp, $identity) === false) {
                throw new RuntimeException('could not stage the age identity.');
            }

            $result = Process::input($ciphertext)->timeout(120)->run([$this->ageBin, '-d', '-i', $tmp]);
            if (! $result->successful()) {
                throw new RuntimeException('age decrypt (with identity) failed: '.trim($result->errorOutput()));
            }

            return $result->output();
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Generate a fresh age keypair via `age-keygen`.
     *
     * @return array{identity: string, recipient: string} identity = the full
     *                                                    `AGE-SECRET-KEY-...` material (with the `# public key:` comment line);
     *                                                    recipient = the `age1...` public string parsed from it.
     */
    public function generateKeypair(): array
    {
        $result = Process::timeout(60)->run([$this->keygenBin]);
        if (! $result->successful()) {
            throw new RuntimeException('age-keygen failed: '.trim($result->errorOutput()));
        }

        $identity = $result->output();
        if (! preg_match('/^#\s*public key:\s*(age1[0-9a-z]+)\s*$/mi', $identity, $m)) {
            throw new RuntimeException('age-keygen output did not contain a public key line.');
        }

        return ['identity' => $identity, 'recipient' => $m[1]];
    }
}
