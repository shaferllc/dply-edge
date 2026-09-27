# A second data region, paired with Cloudflare WNAM (docs/DATA_REGIONS.md).
# Its own state, apart from nyc3:
#   terraform workspace new sfo3
#   TF_VAR_do_token=... terraform apply -var-file=regions/sfo3.tfvars
# Then the gateway, its certificate and its DNS (in Cloudflare) in one go:
#   (with the sfo3 workspace selected) DOMAIN=sfo.dply.io ./apply.sh
# and add the region to DPLY_VALKEY_REGIONS in the app's env:
#   {"key":"sfo3","label":"San Francisco","cloudflare":"WNAM","api_url":"https://api.sfo.dply.io","token":"...","domain":"cache.sfo.dply.io","db_domain":"db.sfo.dply.io"}
region          = "sfo3"
cluster_name    = "dply-pods-sfo3"
create_registry = false
