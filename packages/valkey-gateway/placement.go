package main

import corev1 "k8s.io/api/core/v1"

// Pro tenants run on their own node pools (deploy/valkey/terraform: pro-16,
// pro-64). Both pools start at zero nodes and are tainted, so nothing else
// lands there: when a pro tenant's pod can't be placed, the cluster
// autoscaler adds a node to the pool its nodeSelector names, and removes it
// once the last pro tenant on it is gone. Flex tenants stay on the shared pool.
const (
	proTaintKey  = "dply.dev/pro"
	nodePoolKey  = "doks.digitalocean.com/node-pool"
	proSmallPool = "pro-16" // m-2vcpu-16gb: Pro 5 GB and 12 GB
	proLargePool = "pro-64" // m-8vcpu-64gb: Pro 25 GB and 50 GB
	proSmallMax  = 12288
)

// proPlacement returns the nodeSelector and tolerations for a pod, or nil for
// a flex tenant.
func proPlacement(memoryMB int, persistent bool) (map[string]string, []corev1.Toleration) {
	if !persistent {
		return nil, nil
	}
	pool := proSmallPool
	if memoryMB > proSmallMax {
		pool = proLargePool
	}
	return map[string]string{nodePoolKey: pool},
		[]corev1.Toleration{{Key: proTaintKey, Operator: corev1.TolerationOpEqual, Value: "true", Effect: corev1.TaintEffectNoSchedule}}
}
