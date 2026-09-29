import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Laravel Echo / Reverb is initialized in resources/views/partials/reverb-echo-module.blade.php
 * (import map + ES module from esm.sh) so connection options never come from stale public/build chunks.
 */

/**
 * Subscribe to org-scoped server updates (Reverb) and refresh Livewire when provisioning changes.
 */
function bindDplyOrganizationServerChannel() {
    if (!window.Echo || typeof window.Livewire === 'undefined') {
        return;
    }

    const el = document.getElementById('dply-broadcast-context');
    const orgId = el?.dataset?.organizationId?.trim() ?? '';
    // Keep the current user id current even when the channel sub is reused below,
    // so the backup toast filter always compares against the logged-in operator.
    window.__dplyCurrentUserId = el?.dataset?.userId?.trim() ?? '';

    if (!orgId) {
        if (window.__dplyOrgEchoSub) {
            window.Echo.leave(`organization.${window.__dplyOrgEchoSub}`);
            window.__dplyOrgEchoSub = null;
        }
        return;
    }

    if (window.__dplyOrgEchoSub === orgId) {
        return;
    }

    if (window.__dplyOrgEchoSub) {
        window.Echo.leave(`organization.${window.__dplyOrgEchoSub}`);
    }

    window.__dplyOrgEchoSub = orgId;

    const orgChannel = window.Echo.private(`organization.${orgId}`);

    orgChannel.listen('.server.state.updated', (payload) => {
        window.Livewire.dispatch('server-state-updated', {
            organizationId: payload.organization_id,
            action: payload.action,
            serverId: payload.server_id,
            server: payload.server,
        });
    });

    // Per-job worker-pool events — true real-time Horizon dashboard (no polling).
    orgChannel.listen('.worker-pool.job', (payload) => {
        window.Livewire.dispatch('worker-pool-job', {
            poolId: payload.pool_id,
            job: payload.job,
        });
    });

    // Deploy started / moved a step / finished → the deploy pill
    // (resources/js/deploy-pill.js), which decides what this member may see.
    orgChannel.listen('.edge.deployment.progress', (payload) => {
        window.dispatchEvent(new CustomEvent('dply-deploy-progress', { detail: payload }));
    });

    // Backup finished (success/failure) → transient app-wide toast for the
    // operator who triggered it, no matter which page they're on. Filtered to
    // the triggering user so other org admins on the same channel aren't spammed.
    orgChannel.listen('.backup.status', (payload) => {
        if (!payload || String(payload.user_id) !== String(window.__dplyCurrentUserId ?? '')) {
            return;
        }
        window.dispatchEvent(new CustomEvent('toast', {
            detail: {
                message: payload.message ?? 'Backup finished',
                type: payload.type ?? 'success',
            },
        }));
    });

    // Quick download finished (ready/failed) → transient app-wide toast for the
    // requester, no matter which page they're on. Same shape + user filter as the
    // backup toast above; the in-app bell stays the durable record.
    orgChannel.listen('.quick-download.status', (payload) => {
        if (!payload || String(payload.user_id) !== String(window.__dplyCurrentUserId ?? '')) {
            return;
        }
        window.dispatchEvent(new CustomEvent('toast', {
            detail: {
                message: payload.message ?? 'Your download is ready',
                type: payload.type ?? 'success',
            },
        }));
    });
}

/**
 * Subscribe to server-scoped log snapshots (Reverb) so extra tabs stay in sync without duplicate SSH tails.
 */
function bindDplyServerLogChannel() {
    if (!window.Echo || typeof window.Livewire === 'undefined') {
        return;
    }

    const el = document.getElementById('dply-server-log-broadcast-context');
    const serverId = el?.dataset?.serverId?.trim() ?? '';
    const subscribe = el?.dataset?.subscribe === '1';

    if (!subscribe || !serverId) {
        if (window.__dplyServerLogEchoSub) {
            window.Echo.leave('server.' + window.__dplyServerLogEchoSub);
            window.__dplyServerLogEchoSub = null;
        }
        return;
    }

    if (window.__dplyServerLogEchoSub === serverId) {
        return;
    }

    if (window.__dplyServerLogEchoSub) {
        window.Echo.leave('server.' + window.__dplyServerLogEchoSub);
    }

    window.__dplyServerLogEchoSub = serverId;

    window.Echo.private('server.' + serverId).listen('.server.workspace.log.snapshot', (payload) => {
        window.Livewire.dispatch('server-workspace-log-snapshot', payload);
    });
}

/**
 * Cron “Run now”: queued SSH + Reverb chunks on private server channel.
 */
function bindDplyServerCronRunChannel() {
    if (!window.Echo || typeof window.Livewire === 'undefined') {
        return;
    }

    const el = document.getElementById('dply-server-cron-run-context');
    const serverId = el?.dataset?.serverId?.trim() ?? '';
    const subscribe = el?.dataset?.subscribe === '1';

    if (!subscribe || !serverId) {
        if (window.__dplyCronRunEchoSub) {
            window.Echo.leave('server.' + window.__dplyCronRunEchoSub);
            window.__dplyCronRunEchoSub = null;
        }

        return;
    }

    if (window.__dplyCronRunEchoSub === serverId) {
        return;
    }

    if (window.__dplyCronRunEchoSub) {
        window.Echo.leave('server.' + window.__dplyCronRunEchoSub);
    }

    window.__dplyCronRunEchoSub = serverId;

    const ch = window.Echo.private('server.' + serverId);

    /**
     * Queue workers often broadcast before the Livewire response runs $this->js(), so
     * __dplyCronRunActiveId can be unset — adopt run_id from the first event on this channel.
     */
    function cronRunAccepts(payloadRunId) {
        if (!payloadRunId) {
            return false;
        }
        if (!window.__dplyCronRunActiveId) {
            window.__dplyCronRunActiveId = payloadRunId;

            return true;
        }

        return payloadRunId === window.__dplyCronRunActiveId;
    }

    ch.listen('.server.cron.run.meta', (payload) => {
        if (!payload?.meta_html || !cronRunAccepts(payload.run_id)) {
            return;
        }
        window.Livewire.dispatch('cron-run-meta', {
            runId: payload.run_id,
            metaHtml: payload.meta_html,
        });
    });

    ch.listen('.server.cron.run.chunk', (payload) => {
        if (!payload?.chunk || !cronRunAccepts(payload.run_id)) {
            return;
        }
        window.Livewire.dispatch('cron-run-chunk', {
            runId: payload.run_id,
            chunk: payload.chunk,
        });
    });

    /**
     * Chunks/meta update Livewire state above (live). Completion clears the run and flashes.
     * wire:poll still syncs cache when Reverb is off or events are missed.
     */
    ch.listen('.server.cron.run.completed', (payload) => {
        if (!payload?.run_id || !cronRunAccepts(payload.run_id)) {
            return;
        }
        window.Livewire.dispatch('cron-run-finished', {
            runId: payload.run_id,
            success: !!payload.success,
            flashSuccess: payload.flash_success ?? null,
            error: payload.error ?? null,
        });
        window.__dplyCronRunActiveId = null;
    });
}

/**
 * Cache workspace MONITOR tail: queued SSH + Reverb chunks on private server channel.
 * Mirrors the cron-run binder above. Subscribes when the workspace renders the
 * #dply-server-cache-monitor-context element with subscribe="1" (i.e. there is an
 * active run); leaves on navigation away.
 */
function bindDplyServerCacheMonitorChannel() {
    if (!window.Echo || typeof window.Livewire === 'undefined') {
        return;
    }

    const el = document.getElementById('dply-server-cache-monitor-context');
    const serverId = el?.dataset?.serverId?.trim() ?? '';
    const subscribe = el?.dataset?.subscribe === '1';

    if (!subscribe || !serverId) {
        if (window.__dplyCacheMonitorEchoSub) {
            window.Echo.leave('server.' + window.__dplyCacheMonitorEchoSub);
            window.__dplyCacheMonitorEchoSub = null;
        }

        return;
    }

    if (window.__dplyCacheMonitorEchoSub === serverId) {
        return;
    }

    if (window.__dplyCacheMonitorEchoSub) {
        window.Echo.leave('server.' + window.__dplyCacheMonitorEchoSub);
    }

    window.__dplyCacheMonitorEchoSub = serverId;

    const ch = window.Echo.private('server.' + serverId);

    ch.listen('.server.cache.monitor.chunk', (payload) => {
        if (!payload?.run_id || !payload?.chunk) {
            return;
        }
        window.Livewire.dispatch('cache-monitor-chunk', {
            runId: payload.run_id,
            chunk: payload.chunk,
        });
    });

    /**
     * Completion clears the active run-id Livewire-side. wire:poll.1s still
     * syncs the cache buffer fallback when Reverb is off or events are missed.
     */
    ch.listen('.server.cache.monitor.completed', (payload) => {
        if (!payload?.run_id) {
            return;
        }
        window.Livewire.dispatch('cache-monitor-completed', {
            runId: payload.run_id,
            success: !!payload.success,
            lineCount: payload.line_count ?? 0,
            error: payload.error ?? null,
        });
    });
}

/**
 * Systemd service action (Restart/Stop/Start/Reload/Enable/Disable on a unit, single or bulk):
 * fast path for the workspace-services action banner. Subscribes to the private server channel
 * while a queued task id is in flight; on completion broadcast, dispatches a Livewire event so
 * the banner updates immediately. wire:poll.2s remains as a fallback when this channel is off.
 */
function bindDplyServerSystemdActionChannel() {
    if (!window.Echo || typeof window.Livewire === 'undefined') {
        return;
    }

    const el = document.getElementById('dply-server-systemd-action-context');
    const serverId = el?.dataset?.serverId?.trim() ?? '';
    const subscribe = el?.dataset?.subscribe === '1';

    if (!subscribe || !serverId) {
        if (window.__dplySystemdActionEchoSub) {
            window.Echo.leave('server.' + window.__dplySystemdActionEchoSub);
            window.__dplySystemdActionEchoSub = null;
        }

        return;
    }

    if (window.__dplySystemdActionEchoSub === serverId) {
        return;
    }

    if (window.__dplySystemdActionEchoSub) {
        window.Echo.leave('server.' + window.__dplySystemdActionEchoSub);
    }

    window.__dplySystemdActionEchoSub = serverId;

    const ch = window.Echo.private('server.' + serverId);

    /**
     * Workers can broadcast before the Livewire response runs $this->js() to set
     * __dplySystemdActionActiveId — adopt task_id from the first event if not yet known.
     */
    function systemdActionAccepts(payloadTaskId) {
        if (!payloadTaskId) {
            return false;
        }
        if (!window.__dplySystemdActionActiveId) {
            window.__dplySystemdActionActiveId = payloadTaskId;

            return true;
        }

        return payloadTaskId === window.__dplySystemdActionActiveId;
    }

    ch.listen('.server.systemd.action.completed', (payload) => {
        const taskId = payload?.task_id;
        if (!taskId || !systemdActionAccepts(taskId)) {
            return;
        }
        window.Livewire.dispatch('systemd-action-completed', {
            runId: taskId,
            success: !!payload.success,
            error: payload.error ?? null,
            flashSuccess: payload.flash_success ?? null,
            finalOutput: payload.final_output ?? null,
        });
        window.__dplySystemdActionActiveId = null;
    });
}

// Exposed so the workspace blade can re-bind after Livewire morphs the data-subscribe attribute
// from "0" → "1" (i.e. the operator just queued a task on a freshly-loaded page).
window.__dplyBindServicesEcho = bindDplyServerSystemdActionChannel;

function bindDplySiteProvisioningChannel() {
    if (!window.Echo || typeof window.Livewire === 'undefined') {
        return;
    }

    const el = document.getElementById('dply-site-provisioning-context');
    const siteId = el?.dataset?.siteId?.trim() ?? '';
    const subscribe = el?.dataset?.subscribe === '1';

    if (!subscribe || !siteId) {
        if (window.__dplySiteProvisioningEchoSite) {
            window.Echo.leave('site.' + window.__dplySiteProvisioningEchoSite);
            window.__dplySiteProvisioningEchoSite = null;
        }

        return;
    }

    if (window.__dplySiteProvisioningEchoSite === siteId) {
        return;
    }

    if (window.__dplySiteProvisioningEchoSite) {
        window.Echo.leave('site.' + window.__dplySiteProvisioningEchoSite);
    }

    window.__dplySiteProvisioningEchoSite = siteId;

    window.Echo.private('site.' + siteId).listen('.site.provisioning.updated', (payload) => {
        if (payload?.site_id !== siteId) {
            return;
        }

        window.Livewire.dispatch('site-provisioning-updated', {
            siteId: payload.site_id,
            status: payload.status,
            provisioningState: payload.provisioning_state,
        });
    });
}

document.addEventListener('livewire:init', () => {
    bindDplyOrganizationServerChannel();
    bindDplyServerLogChannel();
    bindDplyServerCronRunChannel();
    bindDplyServerCacheMonitorChannel();
    bindDplyServerSystemdActionChannel();
    bindDplySiteProvisioningChannel();
});

document.addEventListener('livewire:navigated', () => {
    bindDplyOrganizationServerChannel();
    bindDplyServerLogChannel();
    bindDplyServerCronRunChannel();
    bindDplyServerCacheMonitorChannel();
    bindDplyServerSystemdActionChannel();
    bindDplySiteProvisioningChannel();
});

// Echo loads in a deferred <head> module (before this bundle); if Livewire beat it, bind when ready.
document.addEventListener('dply:echo-ready', () => {
    bindDplyOrganizationServerChannel();
    bindDplyServerLogChannel();
    bindDplyServerCronRunChannel();
    bindDplyServerCacheMonitorChannel();
    bindDplyServerSystemdActionChannel();
    bindDplySiteProvisioningChannel();
});
