<?php

declare(strict_types=1);

namespace App\Support\Deployment;

final readonly class SiteResourceBinding
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $type,
        public string $mode,
        public bool $required,
        public string $status,
        public string $source,
        public ?string $name = null,
        public array $config = [],
        // When this binding is backed by a persisted SiteBinding row, its id is
        // exposed here so the UI can detach it. Derived (inferred) bindings have
        // no row and so no id.
        public ?string $bindingId = null,
        // Whether the operator can attach/provision/detach this binding from the
        // UI. Derived bindings are still manageable (the action creates a row);
        // the publication binding is owned by the runtime and is not.
        public bool $manageable = true,
    ) {}

    public function isManaged(): bool
    {
        return $this->bindingId !== null;
    }

    /**
     * @return array{
     *     type: string,
     *     mode: string,
     *     required: bool,
     *     status: string,
     *     source: string,
     *     name: ?string,
     *     config: array<string, mixed>,
     *     binding_id: ?string,
     *     manageable: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'mode' => $this->mode,
            'required' => $this->required,
            'status' => $this->status,
            'source' => $this->source,
            'name' => $this->name,
            'config' => $this->config,
            'binding_id' => $this->bindingId,
            'manageable' => $this->manageable,
        ];
    }
}
