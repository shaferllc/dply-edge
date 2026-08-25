<?php

namespace App\Support\Hosts;

use App\Models\Server;

final class HostCapabilities
{
    public function __construct(
        private readonly Server $server,
    ) {}

    public function kind(): string
    {
        return $this->server->hostKind();
    }

    public function supportsSsh(): bool
    {
        return $this->kind() === Server::HOST_KIND_VM;
    }

    public function supportsFirewall(): bool
    {
        return $this->supportsSsh();
    }

    public function supportsCron(): bool
    {
        return $this->supportsSsh();
    }

    public function supportsServicesWorkspace(): bool
    {
        return $this->supportsSsh();
    }

    public function supportsRecipesWorkspace(): bool
    {
        return $this->supportsSsh();
    }

    public function supportsMonitoringWorkspace(): bool
    {
        return $this->supportsSsh();
    }

    public function supportsMachinePhpManagement(): bool
    {
        return $this->supportsSsh();
    }

    public function supportsWebserverProvisioning(): bool
    {
        return $this->supportsSsh();
    }

    public function supportsEnvPushToHost(): bool
    {
        return $this->supportsSsh();
    }

    public function supportsReleaseRollback(): bool
    {
        return $this->supportsSsh();
    }

    public function supportsSshDeployHooks(): bool
    {
        return $this->supportsSsh();
    }

    public function supportsFunctionDeploy(): bool
    {
        return in_array($this->kind(), [
            Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            Server::HOST_KIND_AWS_LAMBDA,
        ], true);
    }

    public function supportsIngressManagement(): bool
    {
        return $this->supportsClusterDeploy();
    }

    public function supportsTestingHostnameProvisioning(): bool
    {
        return $this->supportsSsh();
    }

    public function supportsHttp3Certificates(): bool
    {
        return $this->supportsSsh();
    }

    public function supportsClusterDeploy(): bool
    {
        return $this->kind() === Server::HOST_KIND_KUBERNETES;
    }
}
