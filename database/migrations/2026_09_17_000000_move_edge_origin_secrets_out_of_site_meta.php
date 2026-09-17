<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the hybrid-origin shared secret out of the plaintext `sites.meta`
 * JSON into a dedicated `edge_origin_secrets` column cast `encrypted:array`.
 *
 * `meta` cannot simply be encrypted: it is queried with Postgres JSON
 * operators in several places (`Site::471` `meta->>'fleet_replica_of_site_id'`,
 * `CreateEdgePreviewSite::415` `whereJsonContains('meta->edge->preview_branch')`),
 * and an encrypted column cannot be queried that way. A separate column is
 * also what the sibling secrets on this model already do —
 * `git_deploy_key_private`, `webhook_secret`, `env_file_content`.
 *
 * The same column carries the Cloudflare Access service-token pair used to
 * reach an origin published through a Cloudflare Tunnel.
 *
 * `sites.meta` is `json`, not `jsonb`, so the casts below are load-bearing:
 * the `-` key-removal operator and `jsonb_set` exist only on `jsonb`.
 *
 * IRREVERSIBLE by design. `down()` drops the column rather than writing the
 * secrets back into `meta`: re-planting credentials as plaintext to undo a
 * migration whose whole purpose was to stop storing them that way would be a
 * worse outcome than losing them. A site whose secret is lost can rotate it
 * from Delivery settings — the origin has to be updated with the new value
 * either way. Back up production before running this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->text('edge_origin_secrets')->nullable()->after('edge_provider_credential_id');
        });

        // The backfill must run through the encrypter, so it cannot be done in
        // SQL: `encrypted:array` stores a Laravel-encrypted string, and a raw
        // JSON value written by UPDATE would throw DecryptException on read.
        // This mirrors what the cast does — encryptString(json_encode($value)).
        DB::table('sites')
            ->select('id', 'meta')
            ->whereRaw("meta->'edge'->'origin'->>'auth_secret' IS NOT NULL")
            ->whereRaw("meta->'edge'->'origin'->>'auth_secret' <> ''")
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $meta = json_decode((string) $row->meta, true);
                    $secret = $meta['edge']['origin']['auth_secret'] ?? null;
                    if (! is_string($secret) || $secret === '') {
                        continue;
                    }

                    DB::table('sites')
                        ->where('id', $row->id)
                        ->update([
                            'edge_origin_secrets' => Crypt::encryptString(
                                (string) json_encode(['auth_secret' => $secret]),
                            ),
                        ]);
                }
            });

        DB::statement(<<<'SQL'
            UPDATE sites
            SET meta = jsonb_set(
                meta::jsonb,
                '{edge,origin}',
                (meta->'edge'->'origin')::jsonb - 'auth_secret'
            )::json
            WHERE meta->'edge'->'origin'->>'auth_secret' IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // Irreversible on purpose — see the class docblock. Rotate the secret
        // from Delivery settings if this is ever rolled back.
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('edge_origin_secrets');
        });
    }
};
