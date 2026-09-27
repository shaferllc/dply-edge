/// <reference types="@cloudflare/workers-types" />

export interface Env {
  // Per-app credentials, written by dply at provision time. Keyed both by
  // `key:{appKey}` (connect lookup) and `id:{appId}` (publish lookup).
  APPS: KVNamespace;
  // One Durable Object instance per app id — the channel/presence hub.
  APP_HUB: DurableObjectNamespace;
  ENVIRONMENT?: string;
  // Customer relay only: requests on {label}.{APP_HOST_SUFFIX} are served
  // only for the app whose record.hostname is that host. Unset = no check.
  APP_HOST_SUFFIX?: string;
}

/** Shape of the JSON record dply writes into the APPS KV namespace. */
export interface AppRecord {
  id: string;
  key: string;
  secret: string;
  enabled: boolean;
  maxConnections?: number;
  // Added for the customer relay (`--env apps`). Optional: records written
  // before these fields existed get the defaults in `appLimits()`.
  allowedOrigins?: string[]; // exact Origin values; empty/missing = any
  clientEvents?: boolean; // allow client-* events; missing = false
  maxMessageBytes?: number; // publish data + client event cap; missing = 10240
  hostname?: string; // the app's own {label}.{APP_HOST_SUFFIX}; missing = shared host only
  shards?: number; // hub Durable Objects the app is spread over; missing = 1 (see docs/edge-realtime.md)
}
