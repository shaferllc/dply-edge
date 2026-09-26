package main

import (
	"context"
	"log"
	"os"
	"time"

	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/types"
	"k8s.io/client-go/tools/leaderelection"
	"k8s.io/client-go/tools/leaderelection/resourcelock"
)

// Active/standby. Everything the gateway decides from memory (when a tenant
// last had traffic, which connections to close before a snapshot, which pods
// it already woke) is only right if one gateway serves at a time. So with
// LEADER_ELECT set, gateways share a Lease: the holder labels its own pod
// role=active, the Service selects only that label, and only the holder runs
// the reaper. A standby takes over within leaseDuration when the active one
// dies, and within a couple of seconds when it is shut down.
//
// Without LEADER_ELECT (local, single replica) the gateway is always active.
const (
	activeLabel   = "role"
	activeValue   = "active"
	leaseName     = "valkey-gateway"
	leaseDuration = 15 * time.Second
)

func (g *gateway) runActive(ctx context.Context) {
	if os.Getenv("LEADER_ELECT") == "" {
		go g.reap(ctx)
		return
	}
	pod := os.Getenv("POD_NAME")
	if pod == "" {
		log.Fatal("LEADER_ELECT needs POD_NAME (downward API)")
	}
	lock := &resourcelock.LeaseLock{
		LeaseMeta:  metav1.ObjectMeta{Name: leaseName, Namespace: g.cfg.namespace},
		Client:     g.kube.CoordinationV1(),
		LockConfig: resourcelock.ResourceLockConfig{Identity: pod},
	}
	go leaderelection.RunOrDie(ctx, leaderelection.LeaderElectionConfig{
		Lock:            lock,
		ReleaseOnCancel: true, // a clean shutdown hands over at once
		LeaseDuration:   leaseDuration,
		RenewDeadline:   10 * time.Second,
		RetryPeriod:     2 * time.Second,
		Callbacks: leaderelection.LeaderCallbacks{
			OnStartedLeading: func(ctx context.Context) {
				log.Printf("active: %s", pod)
				g.takeTraffic(ctx, pod)
				g.reap(ctx)
			},
			OnStoppedLeading: func() {
				// Stop taking traffic, then exit: a gateway that lost the
				// lease must not keep sleeping tenants or serving beside
				// the new active one. The pod restarts as a standby.
				_ = g.setActive(context.Background(), pod, false)
				log.Printf("no longer active: %s", pod)
				os.Exit(0)
			},
		},
	})
}

// takeTraffic moves the Service to this pod: first off any other pod still
// labelled active (a dead or partitioned one), then onto this one.
func (g *gateway) takeTraffic(ctx context.Context, self string) {
	pods, err := g.kube.CoreV1().Pods(g.cfg.namespace).List(ctx, metav1.ListOptions{LabelSelector: "app=valkey-gateway," + activeLabel + "=" + activeValue})
	if err == nil {
		for _, p := range pods.Items {
			if p.Name != self {
				_ = g.setActive(ctx, p.Name, false)
			}
		}
	}
	// Without the label nothing reaches this gateway, so keep trying.
	for g.setActive(ctx, self, true) != nil {
		select {
		case <-ctx.Done():
			return
		case <-time.After(2 * time.Second):
		}
	}
}

func (g *gateway) setActive(ctx context.Context, pod string, on bool) error {
	value := `null`
	if on {
		value = `"` + activeValue + `"`
	}
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	patch := []byte(`{"metadata":{"labels":{"` + activeLabel + `":` + value + `}}}`)
	_, err := g.kube.CoreV1().Pods(g.cfg.namespace).Patch(ctx, pod, types.MergePatchType, patch, metav1.PatchOptions{})
	if err != nil {
		log.Printf("label %s active=%v: %v", pod, on, err)
	}
	return err
}
