data "digitalocean_kubernetes_versions" "current" {}

resource "digitalocean_container_registry" "spike" {
  count                  = var.create_registry ? 1 : 0
  name                   = var.registry_name
  subscription_tier_slug = "basic"
  region                 = var.region
}

resource "digitalocean_kubernetes_cluster" "spike" {
  name    = var.name
  region  = var.region
  version = data.digitalocean_kubernetes_versions.current.latest_version

  # Nodes can pull from the account's registry without image pull secrets.
  registry_integration = true

  node_pool {
    name       = "${var.name}-pool"
    size       = var.node_size
    auto_scale = true
    min_nodes  = var.min_nodes
    max_nodes  = var.max_nodes
    labels     = { "dply.dev/role" = "apps" }
  }

  depends_on = [digitalocean_container_registry.spike]
}

# --- Knative Serving (scale to zero + concurrency autoscaling) -------------

resource "helm_release" "knative_operator" {
  name             = "knative-operator"
  repository       = "https://knative.github.io/operator"
  chart            = "knative-operator"
  version          = var.knative_version
  namespace        = "knative-operator"
  create_namespace = true
  wait             = true
}

resource "kubectl_manifest" "knative_serving_namespace" {
  yaml_body = <<-YAML
    apiVersion: v1
    kind: Namespace
    metadata:
      name: knative-serving
  YAML
}

# Kourier is the lightest ingress Knative supports; one DO load balancer in
# front of it. The autoscaler settings below are the scale-to-zero knobs.
resource "kubectl_manifest" "knative_serving" {
  yaml_body = <<-YAML
    apiVersion: operator.knative.dev/v1beta1
    kind: KnativeServing
    metadata:
      name: knative-serving
      namespace: knative-serving
    spec:
      version: "${trimprefix(var.knative_version, "v")}"
      ingress:
        kourier:
          enabled: true
      config:
        network:
          ingress-class: kourier.ingress.networking.knative.dev
        autoscaler:
          enable-scale-to-zero: "true"
          scale-to-zero-grace-period: "30s"
          # Matches the Worker's autoscaling: add a pod when each one is full.
          container-concurrency-target-percentage: "100"
        features:
          kubernetes.podspec-runtimeclassname: enabled
  YAML

  depends_on = [helm_release.knative_operator, kubectl_manifest.knative_serving_namespace]
}

# --- Isolation --------------------------------------------------------------

resource "kubectl_manifest" "gvisor_runtime_class" {
  count     = var.enable_gvisor ? 1 : 0
  yaml_body = <<-YAML
    apiVersion: node.k8s.io/v1
    kind: RuntimeClass
    metadata:
      name: gvisor
    handler: runsc
  YAML
}

# One namespace per org in the real thing; one here to try the policies.
resource "kubectl_manifest" "apps_namespace" {
  yaml_body = <<-YAML
    apiVersion: v1
    kind: Namespace
    metadata:
      name: apps
  YAML
}

# Pods take traffic only from Knative's ingress and activator; egress stays open.
resource "kubectl_manifest" "apps_network_policy" {
  yaml_body = <<-YAML
    apiVersion: networking.k8s.io/v1
    kind: NetworkPolicy
    metadata:
      name: ingress-from-knative-only
      namespace: apps
    spec:
      podSelector: {}
      policyTypes: [Ingress]
      ingress:
        - from:
            - namespaceSelector:
                matchLabels:
                  kubernetes.io/metadata.name: knative-serving
            - namespaceSelector:
                matchLabels:
                  kubernetes.io/metadata.name: kourier-system
  YAML

  depends_on = [kubectl_manifest.apps_namespace]
}

resource "kubectl_manifest" "apps_quota" {
  yaml_body = <<-YAML
    apiVersion: v1
    kind: ResourceQuota
    metadata:
      name: apps
      namespace: apps
    spec:
      hard:
        requests.cpu: "4"
        requests.memory: 12Gi
        limits.cpu: "8"
        limits.memory: 16Gi
  YAML

  depends_on = [kubectl_manifest.apps_namespace]
}
