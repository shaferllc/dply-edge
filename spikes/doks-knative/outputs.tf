output "cluster_id" {
  value = digitalocean_kubernetes_cluster.spike.id
}

output "kubeconfig_command" {
  description = "Writes a kubeconfig for the cluster."
  value       = "doctl kubernetes cluster kubeconfig save ${digitalocean_kubernetes_cluster.spike.id}"
}

output "ingress_ip_command" {
  description = "Kourier's load balancer IP (appears a minute or two after apply)."
  value       = "kubectl -n kourier-system get svc kourier -o jsonpath='{.status.loadBalancer.ingress[0].ip}'"
}
