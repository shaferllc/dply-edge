package main

import (
	"context"
	"log"
	"strconv"
	"sync"
	"time"

	corev1 "k8s.io/api/core/v1"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/labels"
	"k8s.io/client-go/informers"
	listersv1 "k8s.io/client-go/listers/core/v1"
)

// The warm pool, read from a watch instead of the API server (T-021): a wake
// that adopts a pool pod no longer waits on a LIST. The adoption itself is
// still a compare-and-swap PATCH, so a cache a moment behind can only cost a
// conflict and a try of the next pod, never a pod adopted twice.
//
// And the pool follows demand: POOL is the floor per size; each size keeps
// as many warm pods as it handed out in the last poolWindow, up to
// poolCeiling times its floor (a floor of 0: up to poolCeiling) for small
// pods, and one for sizes of 1 GB and up.

const (
	poolWindow  = 15 * time.Minute
	poolCeiling = 4
)

type poolDemand struct {
	mu      sync.Mutex
	adopted map[int][]time.Time // memory MB -> adoption times inside the window
}

// startPoolWatch fills g.poolPods from a shared informer. A watch that
// can't sync leaves it nil and adopt() lists from the API as before.
func (g *gateway) startPoolWatch(ctx context.Context) {
	factory := informers.NewSharedInformerFactoryWithOptions(g.kube, 0,
		informers.WithNamespace(g.cfg.namespace),
		informers.WithTweakListOptions(func(o *metav1.ListOptions) { o.LabelSelector = "app=dply-valkey-pod,role=pool" }))
	pods := factory.Core().V1().Pods()
	lister := pods.Lister()
	informer := pods.Informer()
	factory.Start(ctx.Done())
	syncCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	for !informer.HasSynced() {
		select {
		case <-syncCtx.Done():
			log.Printf("pool watch: did not sync, listing from the API instead")
			return
		case <-time.After(100 * time.Millisecond):
		}
	}
	g.poolMu.Lock()
	g.poolPods = lister
	g.poolMu.Unlock()
	log.Printf("pool watch: synced")
}

// warmPods: ready-or-not pool pods of one size, from the watch when it runs.
func (g *gateway) warmPods(ctx context.Context, mb int) ([]corev1.Pod, error) {
	g.poolMu.Lock()
	lister := g.poolPods
	g.poolMu.Unlock()
	if lister != nil {
		return cachedPods(lister, g.cfg.namespace, mb)
	}
	list, err := g.kube.CoreV1().Pods(g.cfg.namespace).List(ctx, metav1.ListOptions{LabelSelector: poolSelector(mb)})
	if err != nil {
		return nil, err
	}
	return list.Items, nil
}

func cachedPods(lister listersv1.PodLister, namespace string, mb int) ([]corev1.Pod, error) {
	selector, err := labels.Parse(poolSelector(mb))
	if err != nil {
		return nil, err
	}
	found, err := lister.Pods(namespace).List(selector)
	if err != nil {
		return nil, err
	}
	out := make([]corev1.Pod, 0, len(found))
	for _, p := range found {
		out = append(out, *p.DeepCopy())
	}
	return out, nil
}

func poolSelector(mb int) string {
	return "app=dply-valkey-pod,role=pool,memory=" + itoa(mb)
}

// recordAdoption notes that a pool pod of this size was handed to a tenant.
func (d *poolDemand) recordAdoption(mb int, at time.Time) {
	d.mu.Lock()
	defer d.mu.Unlock()
	if d.adopted == nil {
		d.adopted = map[int][]time.Time{}
	}
	d.adopted[mb] = append(prune(d.adopted[mb], at), at)
}

// want: warm pods to keep for a size, given its floor.
func (d *poolDemand) want(mb, floor int, now time.Time) int {
	d.mu.Lock()
	defer d.mu.Unlock()
	recent := prune(d.adopted[mb], now)
	if d.adopted != nil {
		d.adopted[mb] = recent
	}
	ceiling := max(floor, 1) * poolCeiling
	if mb >= 1024 {
		// Big warm pods reserve real memory on small flex nodes: demand
		// raises these to one at most above their floor.
		ceiling = max(floor, 1)
	}
	return min(max(floor, len(recent)), ceiling)
}

func prune(times []time.Time, now time.Time) []time.Time {
	keep := times[:0]
	for _, t := range times {
		if now.Sub(t) < poolWindow {
			keep = append(keep, t)
		}
	}
	return keep
}

func itoa(n int) string { return strconv.Itoa(n) }
