<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use Illuminate\Support\Facades\Process;

/**
 * Ensure Docker Engine is available on the Linux host that drains Edge
 * build jobs ({@see config('edge.build.queue')}). Prod control-plane
 * workers need this — customer VMs do not.
 */
final class EdgeBuildDockerBootstrap
{
    /**
     * User that must reach the Docker socket (Horizon / warm-images).
     *
     * Unset → whoever runs this: the Horizon worker's own user during an
     * inline self-heal, or SUDO_USER under `sudo php artisan …`. A hardcoded
     * www-data default granted the wrong account on dply-provisioned boxes,
     * where Horizon runs as `dply`.
     */
    public static function queueUser(): string
    {
        $valid = static fn (mixed $user): bool => is_string($user)
            && preg_match('/^[a-z_][a-z0-9_-]*\$?$/i', $user) === 1;

        $configured = trim((string) config('edge.build.docker_user', ''));
        if ($valid($configured)) {
            return $configured;
        }

        if (function_exists('posix_geteuid')) {
            if (posix_geteuid() === 0) {
                $sudoUser = getenv('SUDO_USER');
                if ($valid($sudoUser) && $sudoUser !== 'root') {
                    return $sudoUser;
                }
            } else {
                $name = posix_getpwuid(posix_geteuid())['name'] ?? null;
                if ($valid($name)) {
                    return $name;
                }
            }
        }

        return 'www-data';
    }

    /**
     * Absolute docker CLI path. Queue workers often have a stripped PATH
     * (`/usr/bin:/bin`) so a bare `docker` misses OrbStack at /usr/local/bin.
     *
     * Callers: daemonReachable(), probeDetail(), EdgeBuildRunner,
     * WarmEdgeBuildImagesCommand. No schema change.
     * User: "docker CLI not found on PATH we need docker on the server"
     */
    public static function binary(): string
    {
        $configured = trim((string) config('edge.build.docker_binary', ''));
        if ($configured !== '' && is_file($configured) && is_executable($configured)) {
            return $configured;
        }

        foreach (['/usr/bin/docker', '/usr/local/bin/docker', '/opt/homebrew/bin/docker'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        $which = Process::timeout(5)
            ->env(['PATH' => self::searchPath()])
            ->run(['bash', '-lc', 'command -v docker || true']);
        $cli = trim($which->output());

        return $cli !== '' ? $cli : 'docker';
    }

    public static function searchPath(): string
    {
        $extra = '/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin';
        $current = (string) getenv('PATH');

        return $current !== '' ? $extra.':'.$current : $extra;
    }

    /** @phpstan-impure */
    public static function daemonReachable(): bool
    {
        return Process::timeout(10)
            ->env(['PATH' => self::searchPath()])
            ->run([self::binary(), 'version', '--format', '{{.Server.Version}}'])
            ->successful();
    }

    public static function probeDetail(): string
    {
        $cli = self::binary();
        if ($cli === 'docker') {
            $which = trim(Process::timeout(5)->env(['PATH' => self::searchPath()])->run(['bash', '-lc', 'command -v docker || true'])->output());
            if ($which === '') {
                return 'docker CLI not found on PATH';
            }
            $cli = $which;
        }

        $version = Process::timeout(10)->env(['PATH' => self::searchPath()])->run([$cli, 'version', '--format', '{{.Server.Version}}']);
        if ($version->successful()) {
            return 'server '.trim($version->output());
        }

        $err = trim($version->errorOutput() ?: $version->output());
        $err = $err !== '' ? (preg_replace('/\s+/', ' ', $err) ?? $err) : 'docker version failed';

        return 'CLI at '.$cli.'; '.$err;
    }

    /**
     * get.docker.com + systemctl only apply on Linux. A Linux worker with
     * APP_ENV=local used to take the OrbStack hint and skip install — that is
     * how production showed "docker CLI not found on PATH" / "start OrbStack".
     *
     * Callers: EdgeBuildRunner::assertDockerAvailable. No schema change.
     * User: "docker CLI not found on PATH we need docker on the server"
     */
    public static function isLocalDesktopEnvironment(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    /**
     * Install/start Docker Engine and grant $queueUser socket access.
     *
     * @param  callable(string): void  $log
     * @return array{ok: bool, exit_code: int, detail: string}
     */
    public static function ensure(string $queueUser, callable $log): array
    {
        if (self::isLocalDesktopEnvironment()) {
            $detail = self::probeDetail();
            $log("[dply:docker] Local/macOS host — start OrbStack/Docker Desktop instead of autoinstall. {$detail}\n");

            return ['ok' => self::daemonReachable(), 'exit_code' => 1, 'detail' => $detail];
        }

        $user = trim($queueUser);
        if ($user === '' || preg_match('/^[a-z_][a-z0-9_-]*\$?$/i', $user) !== 1) {
            return ['ok' => false, 'exit_code' => 1, 'detail' => 'invalid queue user'];
        }

        $log("[dply:docker] Ensuring Docker Engine for queue user {$user}…\n");

        $script = self::installScript($user);
        $timeout = (int) config('edge.build.docker_install_timeout_seconds', 600);
        $install = Process::timeout($timeout)->run(
            ['bash', '-lc', $script],
            static function (string $type, string $chunk) use ($log): void {
                $log($chunk);
            },
        );

        if (! $install->successful()) {
            $code = $install->exitCode() ?? 1;
            $log('[dply:docker] Install/start script exited '.$code
                .($code === 42 ? ' (need root or passwordless sudo).' : '.')."\n");

            return ['ok' => false, 'exit_code' => $code, 'detail' => self::probeDetail()];
        }

        $log("[dply:docker] Install/start finished — waiting for daemon…\n");
        if (! self::waitForDaemon($log, $user)) {
            return ['ok' => false, 'exit_code' => 1, 'detail' => self::probeDetail()];
        }

        return ['ok' => true, 'exit_code' => 0, 'detail' => self::probeDetail()];
    }

    /**
     * @param  callable(string): void  $log
     */
    public static function waitForDaemon(callable $log, ?string $queueUser = null, int $seconds = 45): bool
    {
        $user = is_string($queueUser) ? trim($queueUser) : '';
        $aclUser = ($user !== '' && preg_match('/^[a-z_][a-z0-9_-]*\$?$/i', $user) === 1)
            ? $user
            : '$(id -un)';

        $deadline = microtime(true) + max(5, $seconds);
        $attempt = 0;
        while (microtime(true) < $deadline) {
            $attempt++;
            if (self::daemonReachable()) {
                $log("[dply:docker] Daemon reachable after {$attempt} probe(s).\n");

                return true;
            }

            Process::timeout(15)->run(['bash', '-lc', <<<SH
if [ "\$(id -u)" -ne 0 ]; then SUDO="sudo -n"; else SUDO=""; fi
if [ -S /var/run/docker.sock ]; then
  \$SUDO setfacl -m "u:{$aclUser}:rw" /var/run/docker.sock 2>/dev/null \
    || \$SUDO chmod 660 /var/run/docker.sock 2>/dev/null \
    || true
fi
SH]);
            usleep(1_000_000);
        }

        return false;
    }

    private static function installScript(string $queueUser): string
    {
        $quotedUser = escapeshellarg($queueUser);

        return <<<SH
set -eu
TARGET_USER={$quotedUser}

if [ "\$(id -u)" -ne 0 ]; then
  if ! sudo -n true 2>/dev/null; then
    echo "[dply:docker] ERROR: run as root (sudo php artisan dply:edge:ensure-build-docker --user=\${TARGET_USER}) or grant the queue user passwordless sudo." >&2
    exit 42
  fi
  SUDO="sudo -n"
else
  SUDO=""
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "[dply:docker] Installing Docker Engine via get.docker.com…"
  curl -fsSL https://get.docker.com -o /tmp/dply-get-docker.sh
  \$SUDO sh /tmp/dply-get-docker.sh
else
  echo "[dply:docker] docker CLI already present — ensuring daemon is running…"
fi

if command -v systemctl >/dev/null 2>&1; then
  \$SUDO systemctl enable docker >/dev/null 2>&1 || true
  if ! \$SUDO systemctl start docker; then
    echo "[dply:docker] systemctl start docker failed — trying alternate unit…" >&2
    \$SUDO systemctl start docker.service || \$SUDO service docker start || true
  fi
elif command -v service >/dev/null 2>&1; then
  \$SUDO service docker start || true
fi

if id "\$TARGET_USER" >/dev/null 2>&1; then
  \$SUDO usermod -aG docker "\$TARGET_USER" || true
  echo "[dply:docker] Added \$TARGET_USER to docker group (recycle Horizon/queue workers to pick up the group)."
else
  echo "[dply:docker] WARNING: user \$TARGET_USER does not exist — skipping usermod." >&2
fi

# The ACL is what lets the *running* worker reach the socket now — the docker
# group from usermod only applies after Horizon restarts. Ubuntu images often
# ship without setfacl.
if ! command -v setfacl >/dev/null 2>&1 && command -v apt-get >/dev/null 2>&1; then
  echo "[dply:docker] Installing acl (setfacl) so socket access applies without a worker restart…"
  \$SUDO env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq acl >/dev/null 2>&1 \
    || { \$SUDO apt-get update -qq >/dev/null 2>&1 && \$SUDO env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq acl >/dev/null 2>&1; } \
    || echo "[dply:docker] WARNING: could not install acl — restart Horizon after this build." >&2
fi

if [ -S /var/run/docker.sock ]; then
  \$SUDO setfacl -m "u:\${TARGET_USER}:rw" /var/run/docker.sock 2>/dev/null \
    || \$SUDO chgrp docker /var/run/docker.sock 2>/dev/null \
    || true
  ls -la /var/run/docker.sock || true
else
  echo "[dply:docker] WARNING: /var/run/docker.sock missing after start attempt." >&2
fi

# Re-apply socket ACL after dockerd recreates /var/run/docker.sock (common on restart).
if command -v systemctl >/dev/null 2>&1; then
  \$SUDO mkdir -p /etc/systemd/system/docker.service.d
  \$SUDO tee /etc/systemd/system/docker.service.d/dply-edge-sock-acl.conf >/dev/null <<UNIT
[Service]
ExecStartPost=/bin/bash -c 'setfacl -m u:\${TARGET_USER}:rw /var/run/docker.sock || true'
UNIT
  \$SUDO systemctl daemon-reload || true
  echo "[dply:docker] Installed systemd ExecStartPost ACL for \${TARGET_USER}."
fi

\$SUDO docker version || docker version || true
SH;
    }
}
