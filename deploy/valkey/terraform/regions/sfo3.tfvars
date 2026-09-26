# A second data region, paired with Cloudflare WNAM (docs/DATA_REGIONS.md).
# Keep its state apart from nyc3:
#   terraform workspace new sfo3
#   terraform apply -var-file=terraform.tfvars -var-file=regions/sfo3.tfvars
#   terraform apply ... -var gateway_ip=<the load balancer IP after apply.sh>
# then deploy the gateway with DOMAIN=sfo.dply.io ./apply.sh and add the
# region to DPLY_VALKEY_REGIONS.
region           = "sfo3"
cluster_name     = "dply-pods-sfo3"
create_registry  = false
dns_suffix       = ".sfo"
manage_db_record = true
